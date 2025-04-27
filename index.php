<?php
// Configuration
$dashboard_config = [
    'network' => getenv('NETWORK') ?: 'CHANGE_ME', // Supported values: BTC, LTC, or XMR
    'node_ip' => getenv('NODE_IP') ?: 'CHANGE_ME', // Usually 127.0.0.1 if ran locally
    'rpc_port' => getenv('RPC_PORT') ?: 'CHANGE_ME', // Default RPC ports: BTC: 8332 | LTC: 9332 | XMR: 18081
    'rpc_user' => getenv('RPC_USER') ?: 'CHANGE_ME', // Note: not usually needed for XMR (leave blank if XMR)
    'rpc_pass' => getenv('RPC_PASS') ?: 'CHANGE_ME', // Note: not usually needed for XMR (leave blank if XMR)
    'show_node_info' => filter_var(getenv('SHOW_NODE_INFO') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'show_blockchain' => filter_var(getenv('SHOW_BLOCKCHAIN') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'show_mempool' => filter_var(getenv('SHOW_MEMPOOL') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'show_mining' => filter_var(getenv('SHOW_MINING') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'show_transactions' => filter_var(getenv('SHOW_TRANSACTIONS') ?: 'true', FILTER_VALIDATE_BOOLEAN),
    'show_fees' => filter_var(getenv('SHOW_FEES') ?: 'true', FILTER_VALIDATE_BOOLEAN),
];

$network = strtoupper($dashboard_config['network']);
$rpc_user = $dashboard_config['rpc_user'];
$rpc_password = $dashboard_config['rpc_pass'];

$errors = [];


// RPC URL Construction
switch ($network) {
    case 'BTC':
        $coin = "Bitcoin";
        $url_schema = "http://{$dashboard_config['node_ip']}:{$dashboard_config['rpc_port']}";
        break;
    case 'LTC':
        $coin = "Litecoin";
        $url_schema = "http://{$dashboard_config['node_ip']}:{$dashboard_config['rpc_port']}";
        break;
    case 'XMR':
        $coin = "Monero";
        $url_schema = "http://{$dashboard_config['node_ip']}:{$dashboard_config['rpc_port']}/json_rpc";
        break;
    default:
        die("Configuration error: 'network' must be BTC, LTC, or XMR.");
}


// RPC function
function call_rpc($method, $params = [], $dashboard_config, $url_schema) {
    global $errors;
    $rpc_user = $dashboard_config['rpc_user'];
    $rpc_password = $dashboard_config['rpc_pass'];
    $network = strtoupper($dashboard_config['network']);

    // For Monero non-JSON-RPC endpoints
    $is_monero_direct = ($network === 'XMR' && !in_array($method, [
        'get_block_count', 'get_block', 'get_block_header_by_height', 'get_block_header_by_hash',
        'get_connections', 'get_info', 'get_last_block_header', 'get_peer_list',
        'get_transaction_pool', 'get_transaction_pool_stats', 'get_transactions',
        'get_height'
    ]));

    if ($network === 'XMR' && in_array($method, ['get_transaction_pool_stats'])) {
        $url = str_replace('/json_rpc', '', $url_schema) . "/$method";
        $payload = json_encode((object)$params);
    } elseif ($network === 'XMR' && in_array($method, ['get_info'])) {
        $url = str_replace('/json_rpc', '', $url_schema) . "/$method";
        $payload = json_encode((object)$params);
    } else {
        $url = $url_schema;
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => 'php-client',
            'method' => $method,
            'params' => $params
        ]);
    }

    $ch = curl_init($url);
    $curl_options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ];

    if (!empty($rpc_user) || !empty($rpc_password)) {
        $curl_options[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
        $curl_options[CURLOPT_USERPWD] = "$rpc_user:$rpc_password";
    }

    curl_setopt_array($ch, $curl_options);

    $response = curl_exec($ch);
    if ($response === false) {
        $errors[] = "cURL error on method `$method`: " . curl_error($ch);
        curl_close($ch);
        return null;
    }

    $result = json_decode($response, true);
    curl_close($ch);

    if (isset($result['error']) && $result['error'] !== null) {
        $errors[] = "RPC error on `$method`: " . json_encode($result['error']);
        return null;
    }

    return $result['result'] ?? $result;
}


// Cache function
function call_rpc_cached($method, $params, $dashboard_config, $url_schema, $ttlOverrides = [], $defaultTTL = 300) {
    global $errors;
    $network = strtolower($dashboard_config['network']);
    $cacheFile = "{$network}_cache.json";

    if (!file_exists($cacheFile)) {
        file_put_contents($cacheFile, '{}');
    }

    $cacheData = json_decode(file_get_contents($cacheFile), true);
    if (!is_array($cacheData)) {
        $cacheData = [];
    }

    $cacheKey = $method . '_' . md5(json_encode($params));
    $now = time();
    $ttl = $ttlOverrides[$method] ?? $defaultTTL;

    if (isset($cacheData[$cacheKey]) && ($now - $cacheData[$cacheKey]['timestamp']) < $ttl) {
        return $cacheData[$cacheKey]['data'];
    }

    $result = call_rpc($method, $params, $dashboard_config, $url_schema);

    if ($result === null) {
        return "Error, see error message below";
    }

    $cacheData[$cacheKey] = [
        'timestamp' => $now,
        'data' => $result
    ];

    file_put_contents($cacheFile, json_encode($cacheData));
    return $result;
}


// TTL per RPC method cache
$ttlOverrides = [
    'getblockchaininfo' => 30,
    'getmempoolinfo' => 20,
    'getnetworkinfo' => 90,
    'getmininginfo' => 60,
    'getchaintxstats' => 1800,
    'estimatesmartfee' => 120
];


// Fetch info based on network
if ($network == 'BTC') {
    if ($dashboard_config['show_blockchain']) {
        $blockinfo = call_rpc_cached('getblockchaininfo', [], $dashboard_config, $url_schema, $ttlOverrides);
        
        if ($blockinfo && is_array($blockinfo)) {
            $blockcount   = number_format($blockinfo['blocks'] ?? 0);
            $headercount  = number_format($blockinfo['headers'] ?? 0);
            $syncprogress = ($blockinfo['verificationprogress'] >= 0.99999) ? '100%' : round($blockinfo['verificationprogress'] * 100) . '%';
            $chainsize = round(($blockinfo['size_on_disk'] ?? 0) / 1000000000, 2) . " GB";
        } else {
            $errors[] = 'Failed to fetch `getblockchaininfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_node_info']) {
        $netinfo = call_rpc_cached('getnetworkinfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($netinfo && is_array($netinfo) && $blockinfo && is_array($blockinfo)) {
            $nodeversion  = $netinfo['subversion'] ?? 'N/A';
            $activechain  = $blockinfo['chain'] ?? 'N/A';
            $ispruned     = ($blockinfo['pruned'] ?? false) ? 'true' : 'false';
            $connections  = $netinfo['connections'] ?? 0;
        } else {
            $errors[] = 'Failed to fetch `getnetworkinfo` or `getblockchaininfo` for node info.';
        }
    }
    
    if ($dashboard_config['show_mempool']) {
        $mempoolinfo = call_rpc_cached('getmempoolinfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mempoolinfo && is_array($mempoolinfo)) {
            $mempooltxns = number_format($mempoolinfo['size'] ?? 0);
            $mempoolsize = number_format($mempoolinfo['bytes'] / (1024 ** (floor(log($mempoolinfo['bytes'], 1024)))) , 2) . " " . ['Bytes', 'KB', 'MB', 'GB'][floor(log($mempoolinfo['bytes'], 1024))] ?? 0;
            $totalfees   = sprintf('%.8f', $mempoolinfo['total_fee'] ?? 0) . " BTC";
        } else {
            $errors[] = 'Failed to fetch `getmempoolinfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_mining']) {
        $mininginfo = call_rpc_cached('getmininginfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mininginfo && is_array($mininginfo) && $blockinfo && is_array($blockinfo)) {
            $difficulty = number_format($blockinfo['difficulty'] ?? 0);
            $hashrate = number_format(
                ($mininginfo['networkhashps'] ?? 0) / (10 ** (3 * floor(log(($mininginfo['networkhashps'] ?? 1), 1000))))
                , 2) . " " . ['H/s', 'KH/s', 'MH/s', 'GH/s', 'TH/s', 'PH/s'][min(floor(log(($mininginfo['networkhashps'] ?? 1), 1000)), 5)];
    
            $blockcount = $blockinfo['blocks'] ?? 0;
    
            $currentsupply = 0.0;
            $halvingInterval = 210000;
            $initialSubsidy = 50.0;
            $lastHeight = $blockcount - 1;
            $halvings = floor($lastHeight / $halvingInterval);
    
            for ($i = 0; $i <= $halvings; $i++) {
                $subsidy = $initialSubsidy / pow(2, $i);
                $start = $i * $halvingInterval;
                $end = min(($i + 1) * $halvingInterval - 1, $lastHeight);
                $blocks = $end - $start + 1;
    
                $currentsupply += $blocks * $subsidy;
            }
    
            $currentsupply = number_format($currentsupply, 0) . " BTC\n";
            $subsidy = 50 / (2 ** $halvings) . " BTC";
            $nextHalvingBlock = (floor($blockcount / 210000) + 1) * 210000;
            $nexthalving = number_format($nextHalvingBlock - $blockcount);
            $retarget = number_format(2016 - ($blockcount % 2016));
        } else {
            $errors[] = 'Failed to fetch `getmininginfo` or `getblockchaininfo` for mining section.';
        }
    }
    
    if ($dashboard_config['show_fees']) {
        $ratename = "sat/vB";
        $fast = call_rpc_cached('estimatesmartfee', [1], $dashboard_config, $url_schema, $ttlOverrides);
        $medium = call_rpc_cached('estimatesmartfee', [6], $dashboard_config, $url_schema, $ttlOverrides);
        $slow = call_rpc_cached('estimatesmartfee', [144], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($fast && isset($fast['feerate'])) {
            $fastfees = round($fast['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch fast fee estimate.';
            $fastfees = 'N/A';
        }
    
        if ($medium && isset($medium['feerate'])) {
            $mediumfees = round($medium['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch medium fee estimate.';
            $mediumfees = 'N/A';
        }
    
        if ($slow && isset($slow['feerate'])) {
            $slowfees = round($slow['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch slow fee estimate.';
            $slowfees = 'N/A';
        }
    }

    if ($dashboard_config['show_transactions']) {
        $chaintxstats = call_rpc_cached('getchaintxstats', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($chaintxstats && is_array($chaintxstats)) {
            $totaltxns   = number_format($chaintxstats['txcount'] ?? 0);
            $averagetxns = sprintf('%.2f', $chaintxstats['txrate'] ?? 0);
            $monthlytxns = number_format($chaintxstats['window_tx_count'] ?? 0);
        } else {
            $totaltxns   = 'N/A';
            $averagetxns = 'N/A';
            $monthlytxns = 'N/A';
    
            $errors[] = 'Unable to fetch `getchaintxstats`. RPC returned null or invalid data.';
        }
    }
} elseif ($network == 'LTC') {
    if ($dashboard_config['show_blockchain']) {
        $blockinfo = call_rpc_cached('getblockchaininfo', [], $dashboard_config, $url_schema, $ttlOverrides);
        
        if ($blockinfo && is_array($blockinfo)) {
            $blockcount   = number_format($blockinfo['blocks'] ?? 0);
            $headercount  = number_format($blockinfo['headers'] ?? 0);
            $syncprogress = ($blockinfo['verificationprogress'] >= 0.99999) ? '100%' : round($blockinfo['verificationprogress'] * 100) . '%';
            $chainsize    = round(($blockinfo['size_on_disk'] ?? 0) / 1000000000, 2) . " GB";
        } else {
            $errors[] = 'Failed to fetch `getblockchaininfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_node_info']) {
        $netinfo = call_rpc_cached('getnetworkinfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($netinfo && is_array($netinfo) && $blockinfo && is_array($blockinfo)) {
            $nodeversion  = $netinfo['subversion'] ?? 'N/A';
            $activechain  = $blockinfo['chain'] ?? 'N/A';
            $ispruned     = ($blockinfo['pruned'] ?? false) ? 'true' : 'false';
            $connections  = $netinfo['connections'] ?? 0;
        } else {
            $errors[] = 'Failed to fetch `getnetworkinfo` or `getblockchaininfo` for node info.';
        }
    }
    
    if ($dashboard_config['show_mempool']) {
        $mempoolinfo = call_rpc_cached('getmempoolinfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mempoolinfo && is_array($mempoolinfo)) {
            $mempooltxns = number_format($mempoolinfo['size'] ?? 0);
            $mempoolsize = number_format($mempoolinfo['bytes'] / (1024 ** (floor(log($mempoolinfo['bytes'], 1024)))) , 2) . " " . ['Bytes', 'KB', 'MB', 'GB'][floor(log($mempoolinfo['bytes'], 1024))] ?? 0;
        } else {
            $errors[] = 'Failed to fetch `getmempoolinfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_mining']) {
        $mininginfo = call_rpc_cached('getmininginfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mininginfo && is_array($mininginfo) && $blockinfo && is_array($blockinfo)) {
            $difficulty = number_format($blockinfo['difficulty'] ?? 0);
            $hashrate = number_format(
                ($mininginfo['networkhashps'] ?? 0) / (10 ** (3 * floor(log(($mininginfo['networkhashps'] ?? 1), 1000))))
                , 2) . " " . ['H/s', 'KH/s', 'MH/s', 'GH/s', 'TH/s', 'PH/s'][min(floor(log(($mininginfo['networkhashps'] ?? 1), 1000)), 5)];

            $blockcount = $blockinfo['blocks'] ?? 0;
    
            $currentsupply = 0.0;
            $halvingInterval = 840000;
            $initialSubsidy = 50.0;
            $lastHeight = $blockcount - 1;
            $halvings = floor($lastHeight / $halvingInterval);
    
            for ($i = 0; $i <= $halvings; $i++) {
                $subsidy = $initialSubsidy / pow(2, $i);
                $start = $i * $halvingInterval;
                $end = min(($i + 1) * $halvingInterval - 1, $lastHeight);
                $blocks = $end - $start + 1;
    
                $currentsupply += $blocks * $subsidy;
            }
    
            $currentsupply = number_format($currentsupply, 0) . " LTC\n";
            $subsidy = 50 / (2 ** $halvings) . " LTC";
            $nextHalvingBlock = (floor($blockcount / 840000) + 1) * 840000;
            $nexthalving = number_format($nextHalvingBlock - $blockcount);
            $retarget = number_format(2016 - ($blockcount % 2016));
        } else {
            $errors[] = 'Failed to fetch `getmininginfo` or `getblockchaininfo` for mining section.';
        }
    }
    
    if ($dashboard_config['show_fees']) {
        $ratename = "lit/vB";
        $fast = call_rpc_cached('estimatesmartfee', [1], $dashboard_config, $url_schema, $ttlOverrides);
        $medium = call_rpc_cached('estimatesmartfee', [6], $dashboard_config, $url_schema, $ttlOverrides);
        $slow = call_rpc_cached('estimatesmartfee', [144], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($fast && isset($fast['feerate'])) {
            $fastfees = round($fast['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch fast fee estimate.';
            $fastfees = 'N/A';
        }
    
        if ($medium && isset($medium['feerate'])) {
            $mediumfees = round($medium['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch medium fee estimate.';
            $mediumfees = 'N/A';
        }
    
        if ($slow && isset($slow['feerate'])) {
            $slowfees = round($slow['feerate'] * 100000000 / 1000);
        } else {
            $errors[] = 'Failed to fetch slow fee estimate.';
            $slowfees = 'N/A';
        }
    }

    if ($dashboard_config['show_transactions']) {
        $chaintxstats = call_rpc_cached('getchaintxstats', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($chaintxstats && is_array($chaintxstats)) {
            $totaltxns   = number_format($chaintxstats['txcount'] ?? 0);
            $averagetxns = sprintf('%.2f', $chaintxstats['txrate'] ?? 0);
            $monthlytxns = number_format($chaintxstats['window_tx_count'] ?? 0);
        } else {
            $totaltxns   = 'N/A';
            $averagetxns = 'N/A';
            $monthlytxns = 'N/A';
    
            $errors[] = 'Unable to fetch `getchaintxstats`. RPC returned null or invalid data.';
        }
    }
} elseif ($network == 'XMR') {

    $ttlOverrides = [
        'get_info' => 30,
        'get_block_count' => 15,
        'get_last_block_header' => 20,
        'get_transaction_pool_stats' => 20,
        'get_miner_data' => 20,
        'get_fee_estimate' => 20,
    ];


    if ($dashboard_config['show_blockchain']) {
        $block_count = call_rpc_cached('get_block_count', [], $dashboard_config, $url_schema, $ttlOverrides);
        $last_block_header = call_rpc_cached('get_last_block_header', [], $dashboard_config, $url_schema, $ttlOverrides);
        $getinfo = call_rpc_cached('get_info', [], $dashboard_config, $url_schema, $ttlOverrides);

        if ($block_count && $last_block_header) {
            $blockcount   = number_format($block_count['count'] ?? 0);
            $syncprogress = $getinfo['synchronized'] ? 'Yes' : 'No';
            $chainsize    = round(($getinfo['database_size'] ?? 0) / 1000000000, 2) . " GB";
            $headercount  = $blockcount;
        } else {
            $errors[] = 'Failed to fetch Monero blockchain info.';
        }
    }

    if ($dashboard_config['show_node_info']) {
        $getinfo = call_rpc_cached('get_info', [], $dashboard_config, $url_schema, $ttlOverrides);

        if ($getinfo && is_array($getinfo)) {
            $nodeversion  = $getinfo['version'] ?? 'N/A';
            $activechain  = $getinfo['nettype'] ?? 'N/A';
            $connections  = $getinfo['incoming_connections_count'] + $getinfo['outgoing_connections_count'];
        } else {
            $errors[] = 'Failed to fetch Monero node info.';
        }
    }

    if ($dashboard_config['show_mempool']) {
        $mempoolinfo = call_rpc_cached('get_transaction_pool_stats', [], $dashboard_config, $url_schema, $ttlOverrides);
        if ($mempoolinfo) {
            $mempooltxns = number_format($mempoolinfo['pool_stats']['txs_total'] ?? 0);
            $mempoolsize = number_format($mempoolinfo['pool_stats']['bytes_total'] / (1024 ** (floor(log($mempoolinfo['pool_stats']['bytes_total'], 1024)))) , 2) . " " . ['Bytes', 'KB', 'MB', 'GB'][floor(log($mempoolinfo['pool_stats']['bytes_total'], 1024))] ?? 0;
            $totalfees   = number_format($mempoolinfo['fee_total'] ?? 0 / 1e12, 6) . " XMR";
        } else {
            $errors[] = 'Failed to fetch Monero mempool info.';
        }
    }

    if ($dashboard_config['show_mining']) {
        $getinfo = call_rpc_cached('get_info', [], $dashboard_config, $url_schema, $ttlOverrides);
        if ($getinfo) {
            $difficulty      = number_format($getinfo['difficulty'] ?? 0);
            $hashrate        = number_format(($getinfo['difficulty'] ?? 0) / 120 / (10 ** (3 * ($i = min(floor(log(max(($getinfo['difficulty'] ?? 1) / 120, 1), 1000)), 5)))), 2) . " " . ['H/s', 'KH/s', 'MH/s', 'GH/s', 'TH/s', 'PH/s'][$i];
            $blockcount      = $getinfo['height'] ?? 0;
        } else {
            $errors[] = 'Failed to fetch Monero mining info.';
        }
    }

    if ($dashboard_config['show_transactions']) {
        $getinfo = call_rpc_cached('get_info', [], $dashboard_config, $url_schema, $ttlOverrides);

        if ($getinfo) {
            $totaltxns   = number_format($getinfo['tx_count'] ?? 0);
            $averagetxns = sprintf('%.2f', $getinfo['txrate'] ?? 0);
            $monthlytxns = number_format($getinfo['window_tx_count'] ?? 0);
        } else {
           $errors[] = 'Failed to fetch Monero transaction info.';
        }

        $mininginfo = call_rpc_cached('get_miner_data', [], $dashboard_config, $url_schema, $ttlOverrides);

        if ($getinfo) {
            $currentsupply   = number_format($mininginfo['already_generated_coins'] / 1000000000000 ?? 0) . " XMR";
        } else {
           $errors[] = 'Failed to fetch Monero mining info.';
        }
    }

    if ($dashboard_config['show_fees']) {
        $getfees = call_rpc_cached('get_fee_estimate', [], $dashboard_config, $url_schema, $ttlOverrides);

        if ($getfees) {
            $ratename = "per kB";
            $fastfees = number_format(($getfees['fees'][3] ?? 0) / 1000000000000, 12) . " XMR";
            $mediumfees = number_format(($getfees['fees'][2] ?? 0) / 1000000000000, 12) . " XMR";
            $slowfees = number_format(($getfees['fees'][1] ?? 0) / 1000000000000, 12) . " XMR";
            $slowestfees = number_format(($getfees['fees'][0] ?? 0) / 1000000000000, 12) . " XMR";
        } else {
           $errors[] = 'Failed to fetch Monero fee estimates.';
        }
    }
    
}

?>


<!DOCTYPE html>
<html>
    <head>
        <meta charset="utf-8">
        <title><?= $coin ?> Node Dashboard</title>
        <meta name="description" content="A simple node dashboard for your <?= $coin ?> node.">
        <meta http-equiv="refresh" content="60">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                margin: 0;
                background: #0e0e0e;
                color: #cfcfcf;
                font-family: 'Courier New', monospace;
                font-size: 18px;
                padding: 1rem;
            }

            .section {
                background: #121212;
                border: 1px solid #222;
                padding: 0.5rem 1rem;
                box-sizing: border-box;
                flex: 0 0 calc(33.333% - 1rem); /* limit to 3 per row */
                max-width: calc(33.333% - 1rem);
                min-width: 250px;
            }

            .section, .stat-row span {
                word-break: break-word;
                overflow-wrap: break-word;
            }


            /* Medium screens: 2 per row */
            @media (max-width: 1240px) {
                .section {
                    flex: 0 0 calc(50% - 1rem);
                    max-width: calc(50% - 1rem);
                }
            }

            /* Small screens: 1 per row */
            @media (max-width: 840px) {
                .section {
                    flex: 1 1 100%;
                    max-width: 100%;
                    min-width: unset;
                }
            }

            .section-title {
                font-weight: bold;
                color: #fff;
                margin-bottom: 0.5rem;
                border-bottom: 1px solid #333;
                padding-bottom: 0.25rem;
            }

            .stat-row {
                display: flex;
                justify-content: space-between;
                padding: 0.15rem 0;
                border-bottom: 1px dotted #2a2a2a;
            }

            .stat-row span:first-child {
                color: #aaa;
            }

            .dashboard-header {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                background-color: #1a1a1a;
                color: #fff;
                padding: 8px 12px;
                font-size: 15px;
                font-weight: bold;
                z-index: 1000;
                box-shadow: 0 1px 4px rgba(0,0,0,0.2);
                text-align: center;
                word-break: break-word;
            }

            .compact-dashboard {
                display: flex;
                flex-wrap: wrap;
                gap: 1rem;
                margin-top: 80px;
            }

            .header-title {
                margin: 0;
                font-size: 18px;
            }

            .dashboard-footer {
                color: #fff;
                font-size: 16px;
                z-index: 1000;
            }
        </style>
    </head>
    <body>
        <div class="dashboard-header">
        <span class="header-title"><?= $coin ?> Node Dashboard</span>
            <br/>
            <span>Updated:</span> <?= date("H:i:s") ?> UTC — <span>Auto-refresh: 60s</span>
        </div>

        <div class="compact-dashboard">

        <?php if ($dashboard_config['show_node_info']): ?>
            <div class="section">
                <div class="section-title">Node Info</div>
                <div class="stat-row"><span>Version</span><span><?= $nodeversion ?></span></div>
                <div class="stat-row"><span>Chain</span><span><?= $activechain ?></span></div>
                <?php if ($network !== 'XMR'): ?>
                <div class="stat-row"><span>Pruned</span><span><?= $ispruned ?></span></div>
                <?php endif; ?>
                <div class="stat-row"><span>Connections</span><span><?= $connections ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_blockchain']): ?>
            <div class="section">
                <div class="section-title">Blockchain</div>
                <div class="stat-row"><span>Block Height</span><span><?= number_format($blockcount) ?></span></div>
                <div class="stat-row"><span>Block Headers</span><span><?= $headercount?></span></div>
                <?php if ($network !== 'XMR'): ?>
                <div class="stat-row"><span>Sync Progress</span><span><?= $syncprogress ?></span></div>
                <?php endif; ?>
                <?php if ($network == 'XMR'): ?>
                <div class="stat-row"><span>Synced</span><span><?= $syncprogress ?></span></div>
                <?php endif; ?>
                <div class="stat-row"><span>Chain Size</span><span><?= $chainsize ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_mempool']): ?>
            <div class="section">
                <div class="section-title">Mempool</div>
                <div class="stat-row"><span>Pending TX Count</span><span><?= $mempooltxns ?></span></div>
                <div class="stat-row"><span>Total Size</span><span><?= $mempoolsize ?></span></div>
                <?php if ($network == 'BTC'): ?>
                <div class="stat-row"><span>Total Fees</span><span><?= $totalfees ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_mining']): ?>
            <div class="section">
                <div class="section-title">Mining</div>
                <div class="stat-row"><span>Difficulty</span><span><?= $difficulty ?></span></div>
                <div class="stat-row"><span>Hash Rate</span><span><?= $hashrate ?></span></div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span>Blocks till retarget</span><span><?= $retarget ?></span></div>
                <div class="stat-row"><span>Blocks till halving</span><span><?= $nexthalving ?></span></div>
                <div class="stat-row"><span>Block Reward</span><span><?= $subsidy ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_transactions']): ?>
            <div class="section">
                <div class="section-title">Transactions</div>
                <div class="stat-row"><span>Total Transactions</span><span><?= $totaltxns ?></span></div>
                <div class="stat-row"><span>Current Supply</span><span><?= $currentsupply ?></span></div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span>Average tx/s (30-days)</span><span><?= $averagetxns ?>%</span></div>
                <div class="stat-row"><span>30-day transactions</span><span><?= $monthlytxns ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_transactions']): ?>
            <div class="section">
                <div class="section-title">Transaction Feerates (<?= $ratename ?>)</div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span>1 block</span><span><?= $fastfees ?></span></div>
                <div class="stat-row"><span>6 blocks</span><span><?= $mediumfees ?></span></div>
                <div class="stat-row"><span>144 blocks</span><span><?= $slowfees ?></span></div>
                <?php elseif ($network == "XMR"): ?>
                <div class="stat-row"><span>Fast</span><span><?= $fastfees ?></span></div>
                <div class="stat-row"><span>Medium</span><span><?= $mediumfees ?></span></div>
                <div class="stat-row"><span>Slow</span><span><?= $slowfees ?></span></div>
                <div class="stat-row"><span>Slowest</span><span><?= $slowestfees ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        </div>

        <?php if (!empty($errors)): ?>
            <div style="color: black; margin-top: 30px; padding: 10px; border: 1px solid red; background: #fee;">
                <h3>Errors:</h3>
                <ul>
                    <?php foreach ($errors as $e): ?>
                        <li><?php echo htmlspecialchars($e); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <br/><br/>
        <div class="dashboard-footer">
            <center>
                <span class="footer-title">Made by <a href="https://tech1k.com">Tech1k</a> | <a href="https://github.com/Tech1k/simple-node-dashboard">Source Code</a> | <a href="https://librenode.com/donate">Donate</a></span>
            </center>
        </div>
    </body>
</html>
