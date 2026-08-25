<?php
/**
 * Returns conservative city/state/ZIP defaults for checkout and shipping estimators.
 */

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

function fasAddressAutofillResponse(array $payload): void
{
    echo json_encode($payload);
    exit;
}

function fasAddressAutofillHeader(array $server, array $names): string
{
    static $headers = null;

    if ($headers === null) {
        $headers = [];
        if (function_exists('getallheaders')) {
            $rawHeaders = getallheaders();
            if (is_array($rawHeaders)) {
                foreach ($rawHeaders as $name => $value) {
                    $headers[strtolower((string) $name)] = (string) $value;
                }
            }
        }
    }

    foreach ($names as $name) {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!empty($server[$serverKey])) {
            return (string) $server[$serverKey];
        }

        $directKey = strtoupper(str_replace('-', '_', $name));
        if (!empty($server[$directKey])) {
            return (string) $server[$directKey];
        }

        $headerKey = strtolower($name);
        if (!empty($headers[$headerKey])) {
            return (string) $headers[$headerKey];
        }
    }

    return '';
}

function fasAddressAutofillClean(string $value, int $maxLength = 120): string
{
    $decoded = rawurldecode(str_replace('+', ' ', $value));
    $decoded = preg_replace('/[^\p{L}\p{N}\s.\'-]/u', '', $decoded) ?? '';
    $decoded = trim(preg_replace('/\s+/', ' ', $decoded) ?? '');

    return substr($decoded, 0, $maxLength);
}

function fasAddressAutofillState(string $state, string $region = ''): string
{
    $candidate = strtoupper(trim($state));
    if (preg_match('/^[A-Z]{2}$/', $candidate)) {
        return $candidate;
    }

    $states = [
        'ALABAMA' => 'AL',
        'ALASKA' => 'AK',
        'ARIZONA' => 'AZ',
        'ARKANSAS' => 'AR',
        'CALIFORNIA' => 'CA',
        'COLORADO' => 'CO',
        'CONNECTICUT' => 'CT',
        'DELAWARE' => 'DE',
        'DISTRICT OF COLUMBIA' => 'DC',
        'FLORIDA' => 'FL',
        'GEORGIA' => 'GA',
        'HAWAII' => 'HI',
        'IDAHO' => 'ID',
        'ILLINOIS' => 'IL',
        'INDIANA' => 'IN',
        'IOWA' => 'IA',
        'KANSAS' => 'KS',
        'KENTUCKY' => 'KY',
        'LOUISIANA' => 'LA',
        'MAINE' => 'ME',
        'MARYLAND' => 'MD',
        'MASSACHUSETTS' => 'MA',
        'MICHIGAN' => 'MI',
        'MINNESOTA' => 'MN',
        'MISSISSIPPI' => 'MS',
        'MISSOURI' => 'MO',
        'MONTANA' => 'MT',
        'NEBRASKA' => 'NE',
        'NEVADA' => 'NV',
        'NEW HAMPSHIRE' => 'NH',
        'NEW JERSEY' => 'NJ',
        'NEW MEXICO' => 'NM',
        'NEW YORK' => 'NY',
        'NORTH CAROLINA' => 'NC',
        'NORTH DAKOTA' => 'ND',
        'OHIO' => 'OH',
        'OKLAHOMA' => 'OK',
        'OREGON' => 'OR',
        'PENNSYLVANIA' => 'PA',
        'RHODE ISLAND' => 'RI',
        'SOUTH CAROLINA' => 'SC',
        'SOUTH DAKOTA' => 'SD',
        'TENNESSEE' => 'TN',
        'TEXAS' => 'TX',
        'UTAH' => 'UT',
        'VERMONT' => 'VT',
        'VIRGINIA' => 'VA',
        'WASHINGTON' => 'WA',
        'WEST VIRGINIA' => 'WV',
        'WISCONSIN' => 'WI',
        'WYOMING' => 'WY',
        'PUERTO RICO' => 'PR',
    ];

    $regionName = strtoupper(fasAddressAutofillClean($region));

    return $states[$regionName] ?? '';
}

function fasAddressAutofillZip(string $postalCode): string
{
    $postalCode = trim($postalCode);
    if (preg_match('/^\d{5}(?:-\d{4})?$/', $postalCode)) {
        return substr($postalCode, 0, 10);
    }

    return '';
}

function fasAddressAutofillBuild(string $city, string $state, string $postalCode, string $country, string $source): ?array
{
    $country = strtoupper(trim($country));
    $state = fasAddressAutofillState($state);
    $postalCode = fasAddressAutofillZip($postalCode);
    $city = fasAddressAutofillClean($city);

    if ($country !== 'US' || $city === '' || $state === '' || $postalCode === '') {
        return null;
    }

    return [
        'city' => $city,
        'state' => $state,
        'zip' => $postalCode,
        'country' => 'US',
        'source' => $source,
    ];
}

function fasAddressAutofillFromCloudflare(array $server): ?array
{
    $country = fasAddressAutofillHeader($server, ['CF-IPCountry', 'CF-IPCOUNTRY']);
    $city = fasAddressAutofillHeader($server, ['CF-IPCity', 'CF-City']);
    $state = fasAddressAutofillHeader($server, ['CF-Region-Code', 'CF-RegionCode']);
    $region = fasAddressAutofillHeader($server, ['CF-Region']);
    $postalCode = fasAddressAutofillHeader($server, ['CF-Postal-Code', 'CF-PostalCode']);

    if ($state === '' && $region !== '') {
        $state = fasAddressAutofillState('', $region);
    }

    return fasAddressAutofillBuild($city, $state, $postalCode, $country, 'cloudflare');
}

function fasAddressAutofillSafeId(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_-]/', '', $value) ?? '';
}

function fasAddressAutofillFromAnalytics(string $sessionId, string $visitorId): ?array
{
    if ($sessionId === '' && $visitorId === '') {
        return null;
    }

    try {
        require_once __DIR__ . '/../src/config/Database.php';

        $db = \FAS\Config\Database::getInstance()->getConnection();
        $queries = [];

        if ($sessionId !== '') {
            $params = [':session_id' => $sessionId];
            $visitorFilter = '';
            if ($visitorId !== '') {
                $visitorFilter = ' AND visitor_id = :visitor_id';
                $params[':visitor_id'] = $visitorId;
            }

            $queries[] = [
                'sql' => 'SELECT cf_country, cf_region, cf_region_code, cf_city, cf_postal_code
                    FROM analytics_sessions
                    WHERE session_id = :session_id' . $visitorFilter . '
                    LIMIT 1',
                'params' => $params,
            ];
        }

        if ($visitorId !== '') {
            $queries[] = [
                'sql' => "SELECT cf_country, cf_region, cf_region_code, cf_city, cf_postal_code
                    FROM analytics_sessions
                    WHERE visitor_id = :visitor_id
                        AND COALESCE(cf_country, '') = 'US'
                        AND COALESCE(cf_city, '') <> ''
                        AND COALESCE(cf_postal_code, '') <> ''
                    ORDER BY COALESCE(last_seen_at, started_at) DESC
                    LIMIT 1",
                'params' => [':visitor_id' => $visitorId],
            ];
        }

        foreach ($queries as $query) {
            $stmt = $db->prepare($query['sql']);
            foreach ($query['params'] as $name => $value) {
                $stmt->bindValue($name, $value, \PDO::PARAM_STR);
            }
            $stmt->execute();
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if (!$row) {
                continue;
            }

            $state = (string) ($row['cf_region_code'] ?? '');
            if ($state === '') {
                $state = fasAddressAutofillState('', (string) ($row['cf_region'] ?? ''));
            }

            $address = fasAddressAutofillBuild(
                (string) ($row['cf_city'] ?? ''),
                $state,
                (string) ($row['cf_postal_code'] ?? ''),
                (string) ($row['cf_country'] ?? ''),
                'analytics_session'
            );

            if ($address !== null) {
                return $address;
            }
        }
    } catch (Throwable $e) {
        error_log('Address autofill lookup failed: ' . $e->getMessage());
    }

    return null;
}

$address = fasAddressAutofillFromCloudflare($_SERVER);

if ($address === null) {
    $sessionId = fasAddressAutofillSafeId((string) ($_GET['session_id'] ?? ''));
    $visitorId = fasAddressAutofillSafeId((string) ($_GET['visitor_id'] ?? ''));
    $address = fasAddressAutofillFromAnalytics($sessionId, $visitorId);
}

fasAddressAutofillResponse([
    'success' => true,
    'has_address' => $address !== null,
    'address' => $address,
]);
