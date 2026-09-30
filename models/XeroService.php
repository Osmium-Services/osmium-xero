<?php

/**
 * Xero Accounting API Service
 *
 * Handles OAuth2 connection (authorize, token exchange, refresh) and the
 * accounting calls needed to push a paid order to Xero as a sales invoice.
 *
 * API Documentation: https://developer.xero.com/documentation/api/accounting/overview
 */

namespace Osmium\Services\Xero\Models;

use Osmium\Core\Library\OsmiumPDO;

class XeroService
{
    private const AUTHORIZE_URL = 'https://login.xero.com/identity/connect/authorize';
    private const TOKEN_URL = 'https://identity.xero.com/connect/token';
    private const CONNECTIONS_URL = 'https://api.xero.com/connections';
    private const API_BASE_URL = 'https://api.xero.com/api.xro/2.0/';
    private const SCOPES = 'offline_access accounting.contacts accounting.invoices';
    private const TOKEN_REFRESH_MARGIN_SECONDS = 120; // Refresh a bit before actual expiry to avoid races

    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private OsmiumPDO $database;
    private string $tablePrefix;

    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        OsmiumPDO $database,
    ) {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->redirectUri = $redirectUri;
        $this->database = $database;
        $this->tablePrefix = $database->tablePrefix();
    }

    // ----------------------------------------
    // OAuth2 connection flow
    // ----------------------------------------

    /**
     * Build the URL to send the admin to in order to authorize this app.
     */
    public function getAuthorizationUrl(string $state): string
    {
        $query = \http_build_query([
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => self::SCOPES,
            'state' => $state,
        ]);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * Exchange an authorization code for tokens, fetch the connected tenant,
     * and persist the connection. Called from the OAuth callback.
     */
    public function completeAuthorization(string $code): array
    {
        $tokens = $this->requestToken([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $tenant = $this->fetchTenant(accessToken: $tokens['access_token']);

        $this->saveConnection(
            tenantId: $tenant['tenantId'],
            tenantName: $tenant['tenantName'],
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
        );

        return $tenant;
    }

    /**
     * The currently connected tenant (organisation), or null if never connected.
     */
    public function getConnection(): ?array
    {
        $sql = "SELECT * FROM {$this->tablePrefix}xero_connection ORDER BY id DESC LIMIT 1";
        $this->database->query($sql);

        return $this->database->single() ?: null;
    }

    /**
     * Remove the stored connection. The admin must re-authorize to reconnect.
     */
    public function disconnect(): void
    {
        $sql = "DELETE FROM {$this->tablePrefix}xero_connection";
        $this->database->query($sql);
        $this->database->execute();
    }

    /**
     * A valid access token for the connected tenant, refreshing it first if
     * it's expired or close to it.
     *
     * @throws \Exception If Xero has never been connected
     */
    private function ensureValidAccessToken(): array
    {
        $connection = $this->getConnection();
        $notConnected = $connection === null;
        if ($notConnected) throw new \Exception('Xero is not connected');

        $expiresAt = \strtotime($connection['access_token_expires_at']);
        $expiringSoon = $expiresAt - \time() <= self::TOKEN_REFRESH_MARGIN_SECONDS;

        if (!$expiringSoon) {
            return ['access_token' => $connection['access_token'], 'tenant_id' => $connection['tenant_id']];
        }

        $tokens = $this->requestToken([
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection['refresh_token'],
        ]);

        $this->saveConnection(
            tenantId: $connection['tenant_id'],
            tenantName: $connection['tenant_name'],
            accessToken: $tokens['access_token'],
            refreshToken: $tokens['refresh_token'],
            expiresIn: $tokens['expires_in'],
        );

        return ['access_token' => $tokens['access_token'], 'tenant_id' => $connection['tenant_id']];
    }

    /**
     * The connection is single-tenant, so refreshing just replaces the one row.
     */
    private function saveConnection(
        string $tenantId,
        string $tenantName,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
    ): void {
        $expiresAt = \date('Y-m-d H:i:s', \time() + $expiresIn);

        $this->disconnect();

        $sql = "INSERT INTO {$this->tablePrefix}xero_connection "
            . "(tenant_id, tenant_name, access_token, refresh_token, access_token_expires_at) "
            . "VALUES (:tenant_id, :tenant_name, :access_token, :refresh_token, :expires_at)";

        $this->database->query($sql);
        $this->database->bind(param: ':tenant_id', value: $tenantId);
        $this->database->bind(param: ':tenant_name', value: $tenantName);
        $this->database->bind(param: ':access_token', value: $accessToken);
        $this->database->bind(param: ':refresh_token', value: $refreshToken);
        $this->database->bind(param: ':expires_at', value: $expiresAt);
        $this->database->execute();
    }

    /**
     * Exchange a code or refresh token for a new access/refresh token pair.
     */
    private function requestToken(array $parameters): array
    {
        $ch = \curl_init(self::TOKEN_URL);
        \curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => \http_build_query($parameters),
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . \base64_encode("{$this->clientId}:{$this->clientSecret}"),
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("Xero token request curl error: {$curlError}");

        $decoded = \json_decode(json: $response, associative: true);

        $hasError = isset($decoded['error']);
        if ($hasError) {
            $description = $decoded['error_description'] ?? $decoded['error'];
            throw new \Exception("Xero token request failed: {$description}");
        }

        return $decoded;
    }

    /**
     * The list of tenants (organisations) this token can access. This app
     * only ever connects one, so the first connection is used.
     */
    private function fetchTenant(string $accessToken): array
    {
        $ch = \curl_init(self::CONNECTIONS_URL);
        \curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = \curl_exec($ch);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("Xero connections request curl error: {$curlError}");

        $connections = \json_decode(json: $response, associative: true);

        $noConnections = empty($connections);
        if ($noConnections) throw new \Exception('No Xero organisation authorized this connection');

        return [
            'tenantId' => $connections[0]['tenantId'],
            'tenantName' => $connections[0]['tenantName'],
        ];
    }

    // ----------------------------------------
    // Accounting API
    // ----------------------------------------

    /**
     * Find a contact by email, creating one if none exists.
     *
     * @return string The Xero ContactID
     */
    public function findOrCreateContact(string $name, string $email): string
    {
        $escapedEmail = \str_replace('"', '\\"', $email);
        $existing = $this->callApi(method: 'GET', path: 'Contacts?where=' . \rawurlencode("EmailAddress==\"{$escapedEmail}\""));

        $found = !empty($existing['Contacts']);
        if ($found) return $existing['Contacts'][0]['ContactID'];

        $created = $this->callApi(method: 'POST', path: 'Contacts', body: [
            'Contacts' => [[
                'Name' => $name,
                'EmailAddress' => $email,
            ]],
        ]);

        return $created['Contacts'][0]['ContactID'];
    }

    /**
     * Create an AUTHORISED sales invoice (ACCREC) for a contact.
     *
     * @param array $lineItems Each item: ['description', 'quantity', 'unitAmount', 'accountCode']
     * @return array ['invoiceId', 'invoiceNumber']
     */
    public function createInvoice(
        string $contactId,
        array $lineItems,
        string $reference,
        string $invoiceDate,
        string $dueDate,
    ): array {
        $xeroLineItems = \array_map(static fn(array $item): array => [
            'Description' => $item['description'],
            'Quantity' => $item['quantity'],
            'UnitAmount' => $item['unitAmount'],
            'AccountCode' => $item['accountCode'],
        ], $lineItems);

        $response = $this->callApi(method: 'POST', path: 'Invoices', body: [
            'Invoices' => [[
                'Type' => 'ACCREC',
                'Contact' => ['ContactID' => $contactId],
                'LineItems' => $xeroLineItems,
                'Date' => $invoiceDate,
                'DueDate' => $dueDate,
                'Reference' => $reference,
                'Status' => 'AUTHORISED',
                'LineAmountTypes' => 'Exclusive',
            ]],
        ]);

        $invoice = $response['Invoices'][0];

        return [
            'invoiceId' => $invoice['InvoiceID'],
            'invoiceNumber' => $invoice['InvoiceNumber'] ?? null,
        ];
    }

    /**
     * Confirm the connection still works by fetching the organisation.
     */
    public function testConnection(): array
    {
        try {
            $response = $this->callApi(method: 'GET', path: 'Organisation');
            $organisation = $response['Organisations'][0] ?? [];

            return [
                'success' => true,
                'message' => 'Connection successful',
                'organisation' => $organisation['Name'] ?? null,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Make an authenticated call to the Xero Accounting API, refreshing the
     * access token first if it's about to expire.
     *
     * @throws \Exception On curl failure, malformed JSON, or an API error
     */
    private function callApi(string $method, string $path, ?array $body = null): array
    {
        $auth = $this->ensureValidAccessToken();

        $headers = [
            'Authorization: Bearer ' . $auth['access_token'],
            'Xero-tenant-id: ' . $auth['tenant_id'],
            'Accept: application/json',
        ];

        $options = [
            CURLOPT_URL => self::API_BASE_URL . $path,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ];

        $hasBody = $body !== null;
        if ($hasBody) {
            $options[CURLOPT_POSTFIELDS] = \json_encode($body, JSON_THROW_ON_ERROR);
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        $ch = \curl_init();
        \curl_setopt_array($ch, $options);

        $response = \curl_exec($ch);
        $httpCode = \curl_getinfo(handle: $ch, option: CURLINFO_HTTP_CODE);
        $curlError = \curl_error($ch);

        if ($curlError) throw new \Exception("Xero API curl error: {$curlError}");

        $decoded = \json_decode(json: $response, associative: true);

        $jsonDecodeError = \json_last_error() !== JSON_ERROR_NONE;
        if ($jsonDecodeError) throw new \Exception("Xero API returned invalid JSON (HTTP {$httpCode}): {$response}");

        $isError = $httpCode >= 400;
        if ($isError) {
            $message = $this->extractErrorMessage($decoded) ?? "HTTP {$httpCode}";
            throw new \Exception("Xero API error: {$message}");
        }

        return $decoded;
    }

    /**
     * Xero puts validation failures in a nested ValidationErrors array on the
     * first element, rather than a single top-level message.
     */
    private function extractErrorMessage(?array $decoded): ?string
    {
        $topLevelMessage = $decoded['Message'] ?? null;
        if ($topLevelMessage) return $topLevelMessage;

        $elementErrors = $decoded['Elements'][0]['ValidationErrors'] ?? [];
        $messages = \array_column($elementErrors, 'Message');

        return empty($messages) ? null : \implode('; ', $messages);
    }
}
