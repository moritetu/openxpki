#!/usr/bin/perl

use lib qw(../lib);
use strict;
use warnings;
# use IO::Socket::SSL qw(debug3);
use SOAP::Lite; # +trace => 'all';
use English;
use Data::Dumper;
use Log::Log4perl qw(:easy);
use TestCGI;
use Connector::Proxy::SOAP::Lite;

use Test::More tests => 7;

package main;

my $result;
my $client = TestCGI::factory('democa');

my $cert_identifier = do { # slurp
    local $INPUT_RECORD_SEPARATOR;
    open my $HANDLE, '<tmp/entity3.id';
    <$HANDLE>;
};

diag('Revocation test - cert identifier '  . $cert_identifier);

# Unauthenticated call - stops in PENDING state
my $oSoap =  Connector::Proxy::SOAP::Lite->new({
    LOCATION => 'https://localhost/soap/democa',
    uri => 'http://schema.openxpki.org/OpenXPKI/SOAP/Revoke',
    method => 'RevokeCertificateByIdentifier',
    ca_certificate_file => 'tmp/chain.pem',
    ssl_ignore_hostname => 1, # makes it easier
});

my $res = $oSoap->get_hash($cert_identifier);
ok($oSoap, 'SOAP Client with Auth');
is($res->{error},'','No error');
is($res->{state}, 'PENDING','State pending without auth, Workflow ' . $res->{id});

# Cleanup first workflow
$result = $client->mock_request({
    'action' => 'workflow!select!wf_action!crr_cleanup!wf_id!'.$res->{id}
});

# Now try with SSL Auth - should be autoapproved
$oSoap =  Connector::Proxy::SOAP::Lite->new({
    LOCATION => 'https://localhost/soap/democa',
    uri => 'http://schema.openxpki.org/OpenXPKI/SOAP/Revoke',
    method => 'RevokeCertificateByIdentifier',
    certificate_file => 'tmp/pkiclient.crt',
    certificate_key_file => 'tmp/pkiclient.key',
    ca_certificate_file => 'tmp/chain.pem',
    ssl_ignore_hostname => 1, # makes it easier
});

$res = $oSoap->get_hash($cert_identifier);

ok($oSoap, 'SOAP Client with Auth');
is($res->{error},'','No error');
is($res->{state}, 'SUCCESS','State SUCCESS with auth,  Workflow ' . $res->{id});

# Check the certificate status via webui
$result = $client->mock_request({
    page => 'certificate!detail!identifier!'.$cert_identifier
});

while (my $line = shift @{$result->{main}->[0]->{content}->{data}}) {
    is( $line->{value}->{value} , 'CRL_ISSUANCE_PENDING', 'certificate status is CRL Pending') if ($line->{format} && $line->{format} eq 'certstatus');
}
