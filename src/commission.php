<?php
declare(strict_types=1);

/*
 * Dealer commission: each dealer is on a commission plan (or the default one). A plan pays a rate on
 * gross profit (monthly price less the cost recorded when it was sold) or on turnover, for the services
 * it counts: the ones we bill the customer for, the ones billed to the dealer, or all of them.
 * aBILLity works out the actual payout; this is the CRM's view of it, for reference.
 */

function commission_plan_for(array $dealer): ?array
{
    return ($dealer['commission_plan_id'] ?? null) ? db_one('SELECT * FROM commission_plans WHERE id = ?', [$dealer['commission_plan_id']])
        : db_one('SELECT * FROM commission_plans WHERE is_default = 1 AND active = 1 ORDER BY id LIMIT 1');
}

function commission_plan_summary(array $plan): string
{
    return rtrim(rtrim(number_format((float)$plan['rate'], 2), '0'), '.') . '% of ' . ($plan['basis'] === 'turnover' ? 'turnover' : 'gross profit')
        . ' on ' . lcfirst(COMMISSION_APPLIES[$plan['applies_to']] ?? '');
}

/**
 * The dealer's commission this month from their customers' live services. Returns the plan and, per customer
 * and in total: turnover, cost, gross profit and commission (per month), plus services left out of a gross
 * profit plan because they have no cost.
 */
function dealer_commission(array $dealer): array
{
    $plan = commission_plan_for($dealer);
    $out = ['plan' => $plan, 'customers' => [], 'turnover' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'commission' => 0.0, 'no_cost' => 0];
    if (!$plan) {
        return $out;
    }
    $where = ['all' => '', 'direct' => ' AND s.bill_to_dealer = 0', 'dealer_billed' => ' AND s.bill_to_dealer = 1'][$plan['applies_to']] ?? '';
    $rows = db_all("SELECT a.id AS account_id, a.name, s.monthly_price, s.cost_price FROM services s JOIN accounts a ON a.id = s.account_id
        WHERE a.parent_id = ? AND s.status IN ('active', 'suspended')$where ORDER BY a.name", [$dealer['id']]);
    $rate = (float)$plan['rate'] / 100;
    foreach ($rows as $r) {
        $c = &$out['customers'][$r['account_id']];
        $c ??= ['account_id' => (int)$r['account_id'], 'name' => $r['name'], 'services' => 0, 'turnover' => 0.0, 'cost' => 0.0, 'profit' => 0.0, 'commission' => 0.0, 'no_cost' => 0];
        $price = (float)$r['monthly_price'];
        $c['services']++;
        if ($plan['basis'] === 'gross_profit' && $r['cost_price'] === null) {
            $c['no_cost']++;
            $out['no_cost']++;
            unset($c);
            continue;
        }
        $cost = (float)$r['cost_price'];
        $c['turnover'] += $price;
        $c['cost'] += $cost;
        $c['profit'] += $price - $cost;
        $c['commission'] += round(($plan['basis'] === 'turnover' ? $price : $price - $cost) * $rate, 2);
        unset($c);
    }
    foreach ($out['customers'] as $c) {
        foreach (['turnover', 'cost', 'profit', 'commission'] as $k) {
            $out[$k] += $c[$k];
        }
    }
    $out['customers'] = array_values($out['customers']);
    return $out;
}
