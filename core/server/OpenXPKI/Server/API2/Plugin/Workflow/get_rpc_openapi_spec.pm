package OpenXPKI::Server::API2::Plugin::Workflow::get_rpc_openapi_spec;
use OpenXPKI::Server::API2::EasyPlugin;

=head1 NAME

OpenXPKI::Server::API2::Plugin::Workflow::get_rpc_openapi_spec

=cut

# Core modules
use List::Util;

# Project modules
use OpenXPKI::Client::Config;
use OpenXPKI::Connector::WorkflowContext;
use OpenXPKI::i18n qw( i18nGettext );
use OpenXPKI::Server::Context qw( CTX );
use OpenXPKI::Server::API2::Types;
use OpenXPKI::Server::API2::Plugin::Workflow::Util;

# Sources for "type" and "format" (subtype):
#   OpenXPKI::Client::UI::Workflow->__render_fields()
#   https://openxpki.readthedocs.io/en/latest/reference/developer/webui.html?highlight=rawlist#formattet-strings-string-format

our %TYPE_MAP = (
    bool => { type => 'boolean' },
    text => { type => 'string' },
    datetime => { type => 'integer', minimum => 0 },
    uploadarea => { type => 'string', format => 'binary' },
    select => { type => 'string' },
    server => { type => 'string' },
    cert_identifier => { type => 'string' },
    cert_subject => { type => 'string' },
    cert_info => { type => 'string' },
    password => { type => 'string', format => 'password' },
    passwordverify => { type => 'string', format => 'password' },
);

our %SUBTYPE_MAP = ( # aka "format"
    ullist => { type => 'array' },
    rawlist => { type => 'array' },
    deflist => { type => 'array' },
    defhash => { type => 'object' },
    cert_info => { description => 'The result is likely to be a JSON string prefixed with "OXJSF1:"', },
    # FIXME: use enum from OpenXPKI::Server::API2::Types
    certstatus => { type => 'string', enum => [ qw( ISSUED REVOKED CRL_ISSUANCE_PENDING EXPIRED ) ] },
);

our %KEY_MAP = (
    pkcs10 => { description => 'The result is likely to be a JSON string prefixed with "OXJSF1:"', },
    pkcs7 => { description => 'The result is likely to be a JSON string prefixed with "OXJSF1:"', },
);


has factory => (
    is => 'rw',
    isa => 'OpenXPKI::Workflow::Factory',
    lazy => 1,
    default => sub { CTX('workflow_factory')->get_factory },
);

=head1 COMMANDS

=head2 get_rpc_openapi_spec

Returns the OpenAPI specification for the given workflow.

Restrictions:

=over

=item *

=back




B<Parameters>

=over

=item * C<workflow> I<Str> - workflow type

=item * C<input> I<ArrayRef> - filter for input parameters (list of allowed parameters)

=item * C<output> I<ArrayRef> - filter for output parameters (list of allowed parameters)

=back

=cut
command "get_rpc_openapi_spec" => {
    workflow => { isa => 'Str', required => 1, },
    input => { isa => 'ArrayRef[Str]', required => 0, default => sub { [] } },
    output => { isa => 'ArrayRef[Str]', required => 0, default => sub { [] } },
} => sub {
    my ($self, $params) = @_;

    my $workflow = $params->workflow;
    my $rpc_conf = OpenXPKI::Client::Config->new('rpc');

    if (not $self->factory->authorize_workflow({ ACTION => 'create', TYPE => $workflow })) {
        OpenXPKI::Exception->throw(
            message => 'User is not authorized to fetch workflow info',
            params => { workflow => $workflow }
        );
    }

    my $head = CTX('config')->get_hash([ 'workflow', 'def', $workflow, 'head' ]);
    my $result = {
        type        => $workflow,
        label       => $head->{label},
        description => $head->{description},
    };

    my $util = OpenXPKI::Server::API2::Plugin::Workflow::Util->new;
    my $success = $util->get_state_info($workflow, 'SUCCESS')
        or OpenXPKI::Exception->throw(
            message => 'State SUCCESS is not defined in given workflow',
            params => { workflow => $workflow }
        );
    my $output = {};
    $output->{ $_->{name} } = $_ for @{ $success->{output} };

    return {
        input => $self->_openapi_field_schema($workflow, $self->_get_input_fields($workflow, 'INITIAL'), $params->input),
        output => $self->_openapi_field_schema($workflow, $output, $params->output),
    };
};

# ... this also filters out fields that are requested but do not exist in the workflow
sub _openapi_field_schema {
    my ($self, $workflow, $wf_fields, $rpc_spec_field_names) = @_;

    my $openapi_spec; # HashRef { fieldname => { type => ... }, fieldname => ... }

    # skip fields defined in RPC spec but not available in workflow
    for my $fieldname ( @$rpc_spec_field_names ) {
        if (not $wf_fields->{$fieldname}) {
            CTX('log')->system->error("Requested parameter '$fieldname' is not defined in workflow '$workflow'");
            next;
        }

        my $openapi_field = {};
        my $wf_field = $wf_fields->{$fieldname};

        # copy some attributes from workflow field spec
        $openapi_field->{$_} = $wf_field->{$_} // "" for qw( name required );

        # translate description
        $openapi_field->{description} = $wf_field->{description} ? i18nGettext($wf_field->{description}) : "";

        # map OpenXPKI to OpenAPI types
        my $wf_type = $wf_field->{type}; # variable used in exception

        OpenXPKI::Exception->throw(
            message => 'Missing OpenAPI type mapping for OpenXPKI parameter type',
            params => { workflow => $workflow, field => $wf_field->{name}, parameter_type => $wf_type }
        ) unless $TYPE_MAP{$wf_type};

        # add type specific OpenAPI attributes
        $openapi_field = { %$openapi_field, %{ $TYPE_MAP{$wf_type} } };

        # TODO: Handle select fields (check if options are specified)

        # add subtype specific OpenAPI attributes
        my $match = $SUBTYPE_MAP{ $wf_field->{format} };
        if ($match) {
            my $hint = delete $match->{description};
            $openapi_field->{description} .= " ($hint)" if $hint;
            $openapi_field = { %$openapi_field, %$match };
        }

        # add fieldname specific OpenAPI attributes
        $match = $KEY_MAP{ $fieldname };
        if ($match) {
            my $hint = delete $match->{description};
            $openapi_field->{description} .= " ($hint)" if $hint;
            $openapi_field = { %$openapi_field, %$match };
        }

        $openapi_spec->{$fieldname} = $openapi_field;
    }

    return $openapi_spec;
}

# Returns a HashRef with field names and their definition
sub _get_input_fields {
    my ($self, $workflow, $query_state) = @_;

    my $result = {};
    my $wf_config = $self->factory->_get_workflow_config($workflow);
    my $state_info = $wf_config->{state};

    #
    # fetch actions in state $query_state from the config
    #
    my @actions = ();

    # get name of first action of $query_state
    my $first_action;
    for my $state (@$state_info) {
        if ($state->{name} eq $query_state) {
            $first_action = $state->{action}->[0]->{name} ;
            last;
        }
    }
    OpenXPKI::Exception->throw(
        message => 'State not found in workflow',
        params => { workflow_type => $workflow, state => $query_state }
    ) unless $first_action;

    push @actions, $first_action;

    # get names of further actions in $query_state
    # TODO This depends on the internal naming of follow up actions in Workflow.
    #      Alternatively we could parse actions again as in OpenXPKI::Server::API2::Plugin::Workflow::Util->_get_config_details which is also not very elegant
    my $followup_state_re = sprintf '^%s_%s_\d+$', $query_state, uc($first_action);
    for my $state (@$state_info) {
        if ($state->{name} =~ qr/$followup_state_re/) {
            push @actions, $state->{action}->[0]->{name} ;
        }
    }

    # get field informations
    for my $action (@actions) {
        my $action_info = $self->factory->get_action_info($action, $workflow);
        my $fields = $action_info->{field};
        for my $f (@$fields) {
            $result->{$f->{name}} = {
                %$f,
                action => $action
            };
        }
    }

    return $result;
}

__PACKAGE__->meta->make_immutable;
