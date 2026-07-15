<?php

/**
 * Console Command Catalog Helper
 *
 * Grouped command lists (name + description + example params) for the CNR
 * and JPRS developer consoles' command picker. Descriptions are sourced from
 * the vendor manuals (`docs/CNR/*.md`, `docs/JPRS/*.md`); where the manual
 * text is ambiguous the description is left generic rather than guessed, and
 * `example` is omitted rather than fabricated.
 *
 * Usage:
 *  helper('console_commands');
 *  $groups = console_commands('cnr');  // or 'jprs'
 */
if (! function_exists('console_commands')) {
    /**
     * @return array<string, list<array{name: string, description: string, example?: array<string, string>}>>
     */
    function console_commands(string $provider): array
    {
        return match ($provider) {
            'cnr'   => cnr_console_commands(),
            'jprs'  => jprs_console_commands(),
            default => [],
        };
    }
}

if (! function_exists('cnr_console_commands')) {
    /**
     * @return array<string, list<array{name: string, description: string, example?: array<string, string>}>>
     */
    function cnr_console_commands(): array
    {
        return [
            'Domains' => [
                ['name' => 'QueryDomainList', 'description' => 'Queries the list of domains in the account.', 'example' => ['domain' => 'example.*']],
                ['name' => 'QueryDomainExpireList', 'description' => 'Lists domain names currently marked for deletion or nearing expiration.'],
                ['name' => 'QueryDomainRenewList', 'description' => 'Lists domain names currently set for automated renewal.'],
                ['name' => 'QueryTransferList', 'description' => 'Searches for transfers of a particular domain.', 'example' => ['domain' => 'example.com']],
                ['name' => 'QueryForeignTransferList', 'description' => 'Lists currently running external (incoming) transfers and the actions available for each.'],
                ['name' => 'CheckDomain', 'description' => 'Checks whether the desired domain name is available and may be registered at the registry.', 'example' => ['domain' => 'example.com']],
                ['name' => 'CheckPremiumDomain', 'description' => 'Checks if the given domain name is a premium domain name.', 'example' => ['domain' => 'example.com']],
                ['name' => 'AddDomain', 'description' => 'Checks domain availability, creates the required contact handles, and registers the domain at the registry.', 'example' => ['domain' => 'example.com', 'period' => '1']],
                ['name' => 'StatusDomain', 'description' => 'Retrieves the current status of a domain: registration/expiration dates, extensions, and other details.', 'example' => ['domain' => 'example.com']],
                ['name' => 'ModifyDomain', 'description' => "Modifies a domain's contact data, name servers, transfer lock, or other special parameters.", 'example' => ['domain' => 'example.com']],
                ['name' => 'RenewDomain', 'description' => "Renews a domain for the given period and the domain's current expiration year.", 'example' => ['domain' => 'example.com', 'period' => '1', 'expiration' => '2027']],
                ['name' => 'ResendNotification', 'description' => 'Resends the email verification for a contact that is validated but not yet verified.', 'example' => ['type' => 'contactverification', 'object' => 'P-ABC1']],
            ],
            'Zones' => [
                ['name' => 'GetZone', 'description' => 'Returns the zone (TLD) of a specific domain name.', 'example' => ['domain' => 'example.com']],
                ['name' => 'GetZoneInfo', 'description' => 'Queries zone information such as allowed periods, IDN capability, and length restrictions.', 'example' => ['zone' => 'com', 'domain' => 'example.com']],
                ['name' => 'QueryZoneList', 'description' => 'Lists prices and the gTLDs/ccTLDs the account is activated for.'],
            ],
            'Contacts' => [
                ['name' => 'QueryContactList', 'description' => 'Searches for contact handles by any of their attributes; wildcards are allowed.', 'example' => ['contact' => 'P-ABC1']],
                ['name' => 'ActivateContact', 'description' => 'Activates a contact using its (non-expiring) email verification trigger code.', 'example' => ['trigger' => '<trigger-code>']],
                ['name' => 'StatusContact', 'description' => 'Retrieves the details of a contact handle.', 'example' => ['contact' => 'P-ABC1']],
            ],
            'Account & misc' => [
                ['name' => 'QueryUserList', 'description' => 'Lists all sub-users or user groups on the account.', 'example' => ['type' => 'USER']],
                ['name' => 'QueryAccountingList', 'description' => 'Gives a detailed overview of all accounting transactions on the account.'],
                ['name' => 'QueryExchangeRates', 'description' => 'Shows the exchange rate between two currencies.', 'example' => ['currencyfrom' => 'USD', 'currencyto' => 'VND']],
                ['name' => 'ConvertCurrency', 'description' => 'Converts a decimal amount from one currency to another.', 'example' => ['amount' => '100', 'from' => 'USD', 'to' => 'VND']],
                ['name' => 'QueryPropertyList', 'description' => 'Lists all properties of a given class, or all properties in total.', 'example' => ['class' => 'domain']],
            ],
        ];
    }
}

if (! function_exists('jprs_console_commands')) {
    /**
     * @return array<string, list<array{name: string, description: string, example?: array<string, string>}>>
     */
    function jprs_console_commands(): array
    {
        return [
            'Domain' => [
                ['name' => 'Domain.Get', 'description' => 'Retrieves the registration details of a domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.GetWithSigningKey', 'description' => 'Retrieves the registration details of a domain name, including its DNSSEC signing key.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.Check', 'description' => 'Checks whether the specified domain name is available for registration.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.Add', 'description' => 'Registers a new domain name.'],
                ['name' => 'Domain.Update', 'description' => 'Updates the registration information of an already registered domain name.'],
                ['name' => 'Domain.Del', 'description' => 'Requests abolition (deletion) of a registered domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.CancelDel', 'description' => 'Cancels a pending abolition (deletion) request for a domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.RecoverTest', 'description' => 'Checks whether a domain name in its temporary suspension period is eligible for registration recovery.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.Recover', 'description' => 'Recovers the registration of a domain name during its temporary suspension period.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.NameServer', 'description' => 'Sets or releases the name servers assigned to a domain name.'],
                ['name' => 'Domain.DomainList', 'description' => 'Retrieves the list of domain names held by a registrant.'],
                ['name' => 'Domain.AgentGet', 'description' => 'Retrieves the designated-service-provider (agent) management information for a domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'Domain.AgentLock', 'description' => 'Locks a domain name against transfer and designated-service-provider-change requests.'],
                ['name' => 'Domain.RenewalUpdate', 'description' => "Performs a limited update of a domain name's registration information at renewal."],
            ],
            'Domain verification (OJP)' => [
                ['name' => 'Domain.Verify.Add', 'description' => 'Registers a domain name subject to registrant verification (OJP).'],
                ['name' => 'Domain.Verify.Update', 'description' => 'Updates the details of a verification-pending domain name (OJP).'],
                ['name' => 'Domain.Verify.Change', 'description' => 'Changes a registered domain name subject to registrant verification (OJP).'],
                ['name' => 'Domain.Verify.Transfer', 'description' => 'Transfers a domain name between registrants, subject to verification (OJP).'],
                ['name' => 'Domain.Verify.PrereqCheck', 'description' => 'Checks registration eligibility for a domain name subject to verification (OJP).'],
                ['name' => 'Domain.Advance.Verify.Add', 'description' => 'Pre-registers (provisionally registers) a domain name pending registrant verification (OJP).'],
                ['name' => 'Domain.Advance.Verify.Update', 'description' => 'Updates the details of a provisionally registered domain name pending verification (OJP).'],
                ['name' => 'Domain.Advance.Verify.Activate', 'description' => 'Completes (activates) full registration of a provisionally registered domain name (OJP).'],
                ['name' => 'Domain.Advance.Verify.PrereqCheck', 'description' => 'Checks pre-registration eligibility for a domain name subject to verification (OJP).'],
            ],
            'Contact' => [
                ['name' => 'Contact.Get', 'description' => 'Retrieves the registration details of a public contact window (Contact).', 'example' => ['ContactID' => 'C1234567']],
                ['name' => 'Contact.Add', 'description' => 'Registers new public contact window (Contact) information.'],
                ['name' => 'Contact.Update', 'description' => 'Updates the registered information of an existing public contact window (Contact).'],
                ['name' => 'Contact.Del', 'description' => 'Deletes the specified public contact window (Contact) information.', 'example' => ['ContactID' => 'C1234567']],
                ['name' => 'Contact.PreCheck', 'description' => "Checks a public contact window's (Contact) details before submitting a registration application."],
            ],
            'Registrant' => [
                ['name' => 'Registrant.Get', 'description' => 'Retrieves the registration details of a registrant.'],
                ['name' => 'Registrant.Add', 'description' => 'Registers a new registrant.'],
                ['name' => 'Registrant.Update', 'description' => 'Updates the registration details of an existing registrant.'],
                ['name' => 'Registrant.Del', 'description' => 'Deletes registered registrant information.'],
                ['name' => 'Registrant.PreCheck', 'description' => 'Checks registrant details before submitting a registration application.'],
                ['name' => 'Registrant.DomainList', 'description' => 'Retrieves the list of domain names held by the specified registrant.'],
            ],
            'Name servers' => [
                ['name' => 'NameServerHost.Get', 'description' => 'Retrieves registered name server host information.'],
                ['name' => 'NameServerHost.Add', 'description' => 'Registers the IP address of a host as name server host information.'],
                ['name' => 'NameServerHost.Update', 'description' => 'Updates registered name server host information.'],
                ['name' => 'NameServerHost.Del', 'description' => 'Deletes registered name server host information.'],
                ['name' => 'NameServerHost.ExtraDomainList', 'description' => 'Retrieves the list of domain names served by a name server host, other than the ones already under management.'],
            ],
            'Transfers' => [
                ['name' => 'DomainTransfer.Request', 'description' => "Requests the transfer of a domain name's registration information between two registrars."],
                ['name' => 'DomainTransfer.Certify', 'description' => 'Approves or denies a pending domain name transfer request.'],
                ['name' => 'DomainTransfer.List', 'description' => 'Retrieves the list of pending domain name transfer confirmations.'],
                ['name' => 'DomainTransfer.Params', 'description' => 'Retrieves the parameters of a pending domain name transfer application.'],
                ['name' => 'DomainTransfer.RequestParams', 'description' => 'Checks the status of a domain name transfer application.'],
                ['name' => 'RegistrantTransfer.Request', 'description' => "Requests a change of the registrant's designated service provider."],
                ['name' => 'RegistrantTransfer.Certify', 'description' => 'Approves or denies a pending designated-service-provider change request.'],
                ['name' => 'RegistrantTransfer.List', 'description' => 'Retrieves the list of pending designated-service-provider change confirmations.'],
                ['name' => 'RegistrantTransfer.Params', 'description' => 'Retrieves the parameters of a pending designated-service-provider change request.'],
                ['name' => 'RegistrantTransfer.RequestParams', 'description' => 'Checks the status of a designated-service-provider change application.'],
            ],
            'Agent change (OJP)' => [
                ['name' => 'AgentChange.Request', 'description' => "Requests a change of a domain name's designated service provider (OJP)."],
                ['name' => 'AgentChange.Certify', 'description' => 'Approves or denies a pending designated-service-provider change request (OJP).'],
                ['name' => 'AgentChange.List', 'description' => 'Retrieves the list of pending designated-service-provider change confirmations (OJP).'],
                ['name' => 'AgentChange.Params', 'description' => 'Retrieves the parameters of a pending designated-service-provider change request (OJP).'],
                ['name' => 'AgentChange.RequestParams', 'description' => 'Checks the status of a designated-service-provider change application (OJP).'],
            ],
            'Auth code & misc' => [
                ['name' => 'AuthCode.Get', 'description' => 'Retrieves the authentication code (AuthCode) for a domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'AuthCode.Update', 'description' => 'Updates the authentication code (AuthCode) for a domain name.'],
                ['name' => 'AuthCode.ExpDateCheck', 'description' => 'Checks the expiration date of the authentication code (AuthCode).', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'WebRedirect.Get', 'description' => 'Retrieves the Web redirect settings for a registered domain name.', 'example' => ['DomainName' => 'example.jp']],
                ['name' => 'WebRedirect.Update', 'description' => 'Requests changes to the Web redirect settings for a registered domain name.'],
                ['name' => 'Protocol.Get', 'description' => 'Retrieves the protocol version information supported by the interface.'],
            ],
        ];
    }
}
