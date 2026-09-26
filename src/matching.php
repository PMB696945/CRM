<?php
declare(strict_types=1);

/* Matching CRM customers to records in external systems (Xero, GoCardless). */

/** Normalise a company name for matching: "The Copper Kettle Café Ltd." → "copper kettle cafe". */
function company_match_key(?string $name): string
{
    $name = strtolower(trim((string)$name));
    $name = strtr($name, ['&' => ' and ', 'é' => 'e', 'è' => 'e', 'á' => 'a', 'ö' => 'o', 'ü' => 'u']);
    $name = preg_replace('/[^a-z0-9 ]+/', ' ', $name);
    $name = preg_replace('/\b(the|ltd|limited|llp|plc|inc|co|uk|mr|mrs|ms|miss|dr)\b/', ' ', $name);
    return trim(preg_replace('/\s+/', ' ', $name));
}

function email_match_key(?string $email): string
{
    return strtolower(trim((string)$email));
}

/**
 * Pair CRM records with external records where a key identifies exactly one of
 * each. Keys are tried in order (strongest first), e.g. account_number, email, name.
 *
 * $external: [externalId => ['email' => ['a@b.com'], 'name' => ['acme'], ...]]
 * $crm:      [crmId      => same shape]
 * Returns [crmId => externalId].
 */
function match_unambiguous(array $external, array $crm, array $keyOrder): array
{
    $pairs = [];
    $takenExternal = [];
    foreach ($keyOrder as $key) {
        $extIndex = [];
        foreach ($external as $extId => $keys) {
            if (isset($takenExternal[$extId])) {
                continue;
            }
            foreach (array_unique(array_filter($keys[$key] ?? [], 'strlen')) as $v) {
                $extIndex[$v][] = $extId;
            }
        }
        $crmIndex = [];
        foreach ($crm as $crmId => $keys) {
            if (isset($pairs[$crmId])) {
                continue;
            }
            foreach (array_unique(array_filter($keys[$key] ?? [], 'strlen')) as $v) {
                $crmIndex[$v][] = $crmId;
            }
        }
        foreach ($crmIndex as $value => $crmIds) {
            $extIds = $extIndex[$value] ?? [];
            if (count($crmIds) !== 1 || count($extIds) !== 1) {
                continue;
            }
            [$crmId, $extId] = [$crmIds[0], $extIds[0]];
            if (!isset($pairs[$crmId]) && !isset($takenExternal[$extId])) {
                $pairs[$crmId] = $extId;
                $takenExternal[$extId] = true;
            }
        }
    }
    return $pairs;
}
