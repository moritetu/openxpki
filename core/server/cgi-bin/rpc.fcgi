#!/usr/bin/perl

use strict;
use warnings;

use CGI;
use CGI::Fast;
use Data::Dumper;
use English;

use JSON;
use MIME::Base64;
use OpenXPKI::Exception;
use OpenXPKI::Client::Simple;
use OpenXPKI::Client::Config;
use OpenXPKI::Client::RPC;
use OpenXPKI::Serialization::Simple;
use OpenXPKI::i18n qw( i18nGettext );

use Log::Log4perl;
use Log::Log4perl::MDC;

our $config = OpenXPKI::Client::Config->new('rpc');
my $log = $config->logger();

$log->info("RPC handler initialized");

my $json = new JSON();
my $use_status_codes = 0;

sub send_output {
    my ($cgi, $result, $canonical_keys) = @_;

    my $status = '200 OK';

    if (defined $result->{error}) {
        if ($result->{error}->{message} && $result->{error}->{message} =~ m{I18N_OPENXPKI_UI}) {
            $result->{error}->{message} = i18nGettext($result->{error}->{message});
        }
        if ($use_status_codes) {
            my ($error) = split(/[:\n]/, $result->{error}->{message});
            $status = sprintf ("%03d %s", ($result->{error}->{code}/100), $error);
        }
    }

    if ($ENV{'HTTP_ACCEPT'} && $ENV{'HTTP_ACCEPT'} eq 'text/plain') {
       print $cgi->header( -type => 'text/plain', charset => 'utf8', -status => $status );
       if ($result->{error}) {
           print 'error.code=' . $result->{error}->{code}."\n";
           print 'error.message=' . $result->{error}->{message}."\n";
       } else {
           print 'id=' . $result->{result}->{id}."\n";
           print 'state=' . $result->{result}->{state}."\n";
           map { printf "data.%s=%s\n", $_, $result->{result}->{data}->{$_} } keys %{$result->{result}->{data}} if ($result->{result}->{data});
       }

    } else {
        # prepare response header
        print $cgi->header( -type => 'application/json', charset => 'utf8', -status => $status );
        $json->max_depth(20);
        $json->canonical( $canonical_keys ? 1 : 0 );
        print $json->encode( $result );
    }

}

while (my $cgi = CGI::Fast->new()) {

    my $client;

    my $conf = $config->config();

    my $rpc = OpenXPKI::Client::RPC->new( config => $config );

    my $method = $cgi->param('method');

    my $input_method = $ENV{'REQUEST_METHOD'};

    $use_status_codes = $conf->{output} && $conf->{output}->{use_http_status_codes};

    # check for request parameters in JSON data (HTTP body)
    my $postdata;
    my $pkcs7_content;
    my $pkcs7;
    if (my $raw = $cgi->param('POSTDATA')) {

        if (!$conf->{input}->{allow_raw_post}) {
            $log->error("RPC no method set in request");
            send_output( $cgi,  { error => {
                code => 40004,
                message=> "RAW post not allowed",
            }});
            next;
        }

        my $content_type = $ENV{'CONTENT_TYPE'} || '';
        if (!$content_type) {
            $content_type =~ 'application/json';
            $log->warn("RPC POSTDATA request without content-type header");
        }

        $log->debug("RPC postdata with Content-Type: $content_type");

        if ($content_type =~ m{\Aapplication/pkcs7}) {
            $input_method = 'PKCS7';
            $pkcs7 = $raw;
            $pkcs7_content = $rpc->backend()->run_command('unwrap_pkcs7_signed_data', {
               pkcs7 => $pkcs7,
            });
            $raw = $pkcs7_content->{value};
            $log->trace("PKCS7 content: " . Dumper  $pkcs7_content) if ($log->is_trace());

        } elsif ($content_type =~ m{\Aapplication/json}) {
            $input_method = 'JSON';

        } else {

            $log->error("Unsupported content type with RPC POSTDATA");
            send_output( $cgi,  { error => {
                code => 40005,
                message=> "Unsupported content type with RPC POSTDATA",
                type => $content_type
            }});
            next;
        }

        # TODO - evaluate security implications regarding blessed objects
        # and consider to filter out serialized objects for security reasons
        $json->max_depth(  $conf->{input}->{parse_depth} || 5 );

        $log->trace("RPC raw postdata : " . $raw) if ($log->is_trace());
        eval{$postdata = $json->decode($raw);};
        if (!$postdata || $EVAL_ERROR) {
            $log->error("RPC decoding postdata failed: " . $EVAL_ERROR);
            send_output( $cgi,  { error => {
                code => 40002,
                message=> "RPC decoding postdata failed",
                data => { pid => $$ }
            }});
            next;
        }
        # read "method" from JSON data if not found in URL before
        $method = $postdata->{method} unless $method;
    }

    $method = $config->route() unless($method);

    # method should be set now
    if ( !$method ) {
        $log->error("RPC no method set in request");
        send_output( $cgi,  { error => {
            code => 40001,
            message=> "RPC no method set in request",
            data => { pid => $$ }
        }});
        next;
    }

    # special handling for requests for OpenAPI (Swagger) spec?
    if ($method eq 'openapi-spec') {
        my $baseurl = sprintf "%s://%s:%s%s", ($cgi->https ? 'https' : 'http'), $cgi->virtual_host, $cgi->virtual_port, $cgi->request_uri;
        my $spec = $rpc->openapi_spec($baseurl);
        if (!$spec) {
            send_output($cgi, { error => { code => 50004, message => "Unable to query OpenAPI specification from OpenXPKI server", data => { pid => $$ } } });
        } else {
            send_output($cgi, $spec, 1);
        }
        next;
    }

    my $servername = $conf->{$method}->{servername} || '';

    Log::Log4perl::MDC->put('server', $servername);
    Log::Log4perl::MDC->put('endpoint', $config->endpoint());

    my $error = '';

    my $workflow_type = $conf->{$method}->{workflow};
    if ( !defined $workflow_type ) {
        $log->error("RPC no workflow_type set for requested method $method");
        send_output( $cgi,  { error => {
            code => 40401,
            message=> "RPC method $method not found or no workflow_type set",
            data => { pid => $$ }
        }});
        next;
    }

    # Only parameters which are whitelisted in the config are mapped!
    # This is crucial to prevent injection of server-only parameters
    # like the autoapprove flag...
    my $param;

    if ($conf->{$method}->{param}) {
        my @keys;
        @keys = split /\s*,\s*/, $conf->{$method}->{param};
        foreach my $key (@keys) {

            my $val;
            if ($postdata) {
                $val = $postdata->{$key};
            } else {
                $val = $cgi->param($key);
            }
            next unless (defined $val);

            if (!ref $val) {
                $val =~ s/\A\s+//;
                $val =~ s/\s+\z//;
            }
            $param->{$key} = $val;
        }
    }

    # if given, append to the paramter list
    if ($servername) {
        $param->{'server'} = $servername;
        $param->{'interface'} = 'rpc';
    }

    my %envkeys;
    if ($conf->{$method}->{env}) {
        %envkeys = map {$_ => 1} (split /\s*,\s*/, $conf->{$method}->{env});
    }

    # IP Transport
    if ($envkeys{'client_ip'}) {
        $param->{'client_ip'} = $ENV{REMOTE_ADDR};
    }

    if ($envkeys{'endpoint'}) {
        $param->{'endpoint'} = $config->endpoint();
    }

    # Gather data from TLS session
    my $auth_dn = '';
    my $auth_pem = '';
    if ( defined $ENV{HTTPS} && lc( $ENV{HTTPS} ) eq 'on' ) {

        $log->debug("calling context is https");
        $auth_dn = $ENV{SSL_CLIENT_S_DN};
        $auth_pem = $ENV{SSL_CLIENT_CERT};
        if ( defined $auth_dn ) {
            $log->info("RPC authenticated client DN: $auth_dn");

            if ($envkeys{'signer_dn'}) {
                $param->{'signer_dn'} = $auth_dn;
            }

            if ($envkeys{'tls_client_dn'}) {
                $param->{'tls_client_dn'} = $auth_dn;
            }

            if ($auth_pem) {
                $param->{'signer_cert'} = $auth_pem if ($envkeys{'signer_cert'});
                $param->{'tls_client_cert'} = $auth_pem if ($envkeys{'tls_client_cert'});
            }
        }
        else {
            $log->debug("RPC unauthenticated (no cert)");
        }
    } else {
        $log->debug("RPC unauthenticated (plain http)");
    }

    if ($pkcs7_content && $envkeys{'pkcs7'}) {
        $param->{'_pkcs7'} = $pkcs7;
    }

    if ($pkcs7_content && $envkeys{'signer_cert'}) {
        $param->{'signer_cert'} = $pkcs7_content->{signer};
    }

    $log->trace( "Calling $method on ".$config->endpoint()." with parameters: " . Dumper $param ) if $log->is_trace;

    my $workflow;
    eval {

        # create the client object
        $client = $rpc->backend() or next;

        # check for pickup parameter
        if (my $pickup_key = $conf->{$method}->{pickup}) {
            my $pickup_value;
            if ($postdata) {
                $pickup_value = $postdata->{$pickup_key};
            } else {
                $pickup_value = $cgi->param($pickup_key);
            }
            if ($pickup_value) {
                $workflow = $rpc->pickup_workflow($conf->{$method}, $pickup_value);
            } else {
                $log->trace( "No pickup because $pickup_key has not a value" ) if $log->is_trace;
            }
        }

        # pickup return undef if no workflow was found
        if (!$workflow) {
            $log->debug("Initialize $workflow_type with params " . join(", ", keys %{$param}));
            $workflow = $client->handle_workflow({
                type => $workflow_type,
                params => $param
            });
        }

        $log->trace( 'Workflow info '  . Dumper $workflow );
    };

    my $res;
    if ( my $exc = OpenXPKI::Exception->caught() ) {
        $log->error("Unable to instantiate workflow: ". $exc->message );
        $res = { error => { code => 50002, message => $exc->message, data => { pid => $$ } } };
    }
    elsif (my $eval_err = $EVAL_ERROR) {

        my $reply = $client->last_reply();
        $log->error(Dumper $reply);

        # Validation error
        my $error = $client->last_error() || $eval_err;

        # TODO this needs to be reworked
        if ($reply->{LIST}->[0]->{LABEL}
            eq 'I18N_OPENXPKI_SERVER_WORKFLOW_VALIDATION_FAILED_ON_EXECUTE' &&
            $reply->{LIST}->[0]->{PARAMS}->{__FIELDS__}) {
            $res = { error => {
                code => 40003,
                message => $error,
                fields => $reply->{LIST}->[0]->{PARAMS}->{__FIELDS__},
                data => { pid => $$ }
            } };

        } else {
            $log->error("Unable to create workflow: ". $error );
            if (!$error || $error !~ /I18N_OPENXPKI_UI_/) {
                $error = 'uncaught error';
            }
            $res = { error => { code => 50002, message => $error, data => { pid => $$ } } };
        }

    } elsif (( $workflow->{'proc_state'} ne 'finished' && !$workflow->{id} ) || $workflow->{'proc_state'} eq 'exception') {

        $log->error("workflow terminated in unexpected state" );
        $res = { error => { code => 50003, message => 'workflow terminated in unexpected state',
            data => { pid => $$, id => $workflow->{id}, 'state' => $workflow->{'state'} } } };

    } else {

        $log->info(sprintf("RPC request was processed properly (Workflow: %01d, State: %s (%s)",
            $workflow->{id}, $workflow->{state}, $workflow->{'proc_state'}) );
        $res = { result => { id => $workflow->{id}, 'state' => $workflow->{'state'}, 'proc_state' => $workflow->{'proc_state'}, pid => $$ }};

        # Map context parameters to the response if requested
        if ($conf->{$method}->{output}) {
            $res->{result}->{data} = {};
            my @keys;
            @keys = split /\s*,\s*/, $conf->{$method}->{output};
            $log->debug("Keys " . join(", ", @keys));
            $log->trace("Raw context: ". Dumper $workflow->{context});
            foreach my $key (@keys) {
                my $val = $workflow->{context}->{$key};
                next unless (defined $val);
                next unless ($val ne '' || ref $val);
                if (OpenXPKI::Serialization::Simple::is_serialized($val)) {
                    $val = OpenXPKI::Serialization::Simple->new()->deserialize( $val );
                }
                $res->{result}->{data}->{$key} = $val;
            }
        }
    }

    send_output( $cgi,  $res );

    if ($client) {
        $client->disconnect();
    }

}


1;

__END__;

=head1 rpc.fcgi

A RPC interface to run workflows

=head1 Configuration

The wrapper uses the OpenXPKI::Client::Config module to load a config
file based on the called script name.

=head2 Basic Configuration

The basic configuration in default.conf must contain log and auth info:

  [global]
  log_config = /etc/openxpki/rpc/log.conf
  log_facility = client.rpc
  socket = /var/openxpki/openxpki.socket

  [auth]
  stack = _System
  pki_realm = democa

=head2 Method Invocation

The parameters are expected in the query string or in the body of a
HTTP POST operation (application/x-www-form-urlencoded). A minimal
request must provide the parameter "method". The name of the used method
must match a section in the config file. The section must at least contain
the name of a workflow:

  [RevokeCertificateByIdentifier]
  workflow = status_system

You need to define parameters what parameters should be mapped from the
input to the workflow. Values for the given keys are copied to the
workflow input parameter with the same name. In addition, you can load
certain information from the environment

  [RevokeCertificateByIdentifier]
  workflow = certificate_revocation_request_v2
  param = cert_identifier, reason_code, comment, invalidity_time
  env = signer_cert, signer_dn, client_ip

The keys I<signer_cert/signer_dn> are only available on authenticated TLS
conenctions and are filled with the PEM block and the full subject dn
of the client certificate. Note that this data is only available if the
ExportCertData and StdEnvVars option is set in the apache config!

If the workflow uses endpoint specific configuraton, you must also set the
name of the server using the I<servername> key.

  [RevokeCertificateByIdentifier]
  workflow = certificate_revocation_request_v2
  param = cert_identifier, reason_code, comment, invalidity_time
  env = signer_cert, signer_dn, client_ip
  servername  = myserver

=head2 Response

=head3 Success

The default response does not include any data from the workflow itself,
it just returns the meta information of workflow:

  {"result":{"id":"300287","pid":4375,"state":"SUCCESS"}}';

I<id> is the workflow id, which can be used in the workflow search to
access this workflow, I<state> is the current state of the workflow.
I<pid> is the internal process id and only relevant for extended debug.

Note: A successfull RPC response does not tell you anything about the
status of the requested business process! It just says that the workflow
ran in a technical expected manner.

=head3 Process Information

You can add a list of workflow context items to be exported with the
response:

    [RequestCertificate]
    workflow = certificate_enroll
    param = pkcs10, comment
    output = cert_identifier, error_code

This will add a new section I<data> to the response with the value of the
named context item. Items are only included if they exist.

    {"result":{"id":"300287","pid":4375,"state":"SUCCESS",
        "data":{"cert_identifier":"i7Dvxp7fz_9WZlzf9hW_9tlbF6M"},
    }}

=head3 Error Response

In case the workflow can not be created or terminates with an unexpected
error, the return structure looks different:

 {"error":{"data":{"pid":4567,"id":12345},"code":42,
     "message":"I18N_OPENXPKI_SERVER_ACL_AUTHORIZE_WORKFLOW_CREATE_PERMISSION_DENIED"
 }}

The message gives a verbose description on what happend, the data node will
contain the workflow id only in case it was started.

Error code in the range 4xx indicate a client error, 5xx a problem on the
server (which might also be related to on inappropriate input data).

=over

=item 40001 - no method in request

No method name could be found in the request.

=item 40002 - decoding of POST data failed

Data send as JSON POST could not be parsed. The reason is either malformed
JSON structure or the data has nested structures exceeding the parse_depth.

=item 40003 - wrong input values

The given parameters do not pass the input validation rules of the workflow.
You will find the verbose error in I<message> and the list of affected fields
in the I<fields> key.

=item 40004 - RAW Post not allowed

RAW post was detected but is not allowed by configuration.

=item 40005 - RAW Post with unknown Content-Type

The Content-Type set with a RAW post request is not known.
Supported types are application/json and application/pkcs7.

=item 40401 - Invalid method / setup incomplete

The method name given is either not defined or has no workflow defined

=item 50001 - Error creating RPC client

The webserver was unable to setup the RPC client side. Details can be found
in the error message. Common reason is that the server is too busy or not
running and unable to handle the request at all.

=item 50002 - server exception

The server ran into an exception while handling the request, details might
be found in the error message.

=item 50003 - workflow error

The request was handled by the server properly but the workflow has
encountered an unexpected state.

=back

=head2 TLS Authentication

In case you want to use TLS Client authentication you must tell the
webserver to pass the client certificate to the script. For apache,
put the following lines into your SSL Host section:

    <Location /rpc>
        SSLVerifyClient optional
        SSLOptions +StdEnvVars +ExportCertData
    </Location>


