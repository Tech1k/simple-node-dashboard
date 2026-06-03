<?php
// Read a boolean from an environment variable, falling back to a default when unset.
// Accepts true/false, 1/0, yes/no, on/off.
function env_bool($name, $default) {
    $value = getenv($name);
    return $value === false ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
}

// Configuration
// Values may be set directly below, or overridden via environment variables (e.g. for Docker).
$dashboard_config = [
    'network' => getenv('NETWORK') ?: 'CHANGE_ME', // Supported values: BTC, LTC, or XMR
    'node_ip' => getenv('NODE_IP') ?: 'CHANGE_ME', // Usually 127.0.0.1 if ran locally
    'rpc_port' => getenv('RPC_PORT') ?: 'CHANGE_ME', // Default RPC ports: BTC: 8332 | LTC: 9332 | XMR: 18081
    'rpc_user' => getenv('RPC_USER') ?: 'CHANGE_ME', // Note: not usually needed for XMR (leave blank if XMR)
    'rpc_pass' => getenv('RPC_PASS') ?: 'CHANGE_ME', // Note: not usually needed for XMR (leave blank if XMR)
    'show_node_info' => env_bool('SHOW_NODE_INFO', true),
    'show_blockchain' => env_bool('SHOW_BLOCKCHAIN', true),
    'show_mempool' => env_bool('SHOW_MEMPOOL', true),
    'show_mining' => env_bool('SHOW_MINING', true),
    'show_transactions' => env_bool('SHOW_TRANSACTIONS', true),
    'show_fees' => env_bool('SHOW_FEES', true),
    'theme' => getenv('THEME') ?: 'dark', // Default theme: dark, light, nord, solarized, or dracula
    'show_theme_switcher' => env_bool('SHOW_THEME_SWITCHER', true), // Set false to lock the theme above and hide the dropdown
];

$network = strtoupper($dashboard_config['network']);
$rpc_user = $dashboard_config['rpc_user'];
$rpc_password = $dashboard_config['rpc_pass'];
$theme = $dashboard_config['theme'] ?? 'dark';
$show_theme_switcher = $dashboard_config['show_theme_switcher'] ?? true;

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
        return null;
    }

    $cacheData[$cacheKey] = [
        'timestamp' => $now,
        'data' => $result
    ];

    file_put_contents($cacheFile, json_encode($cacheData));
    return $result;
}


// Format a byte count as a human-readable size (handles 0 safely)
function format_bytes($bytes) {
    $bytes = (float) $bytes;
    if ($bytes <= 0) {
        return "0 Bytes";
    }
    $units = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    $unit = min(max((int) floor(log($bytes, 1024)), 0), count($units) - 1);
    return number_format($bytes / (1024 ** $unit), 2) . " " . $units[$unit];
}


// Format a hashes-per-second value as a human-readable rate (handles 0 safely)
function format_hashrate($hashps) {
    $hashps = (float) $hashps;
    if ($hashps <= 0) {
        return "0 H/s";
    }
    $units = ['H/s', 'KH/s', 'MH/s', 'GH/s', 'TH/s', 'PH/s'];
    $unit = min(max((int) floor(log($hashps, 1000)), 0), count($units) - 1);
    return number_format($hashps / (1000 ** $unit), 2) . " " . $units[$unit];
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
            $blockcount   = $blockinfo['blocks'] ?? 0;
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
            $mempoolsize = format_bytes($mempoolinfo['bytes'] ?? 0);
            $totalfees   = sprintf('%.8f', $mempoolinfo['total_fee'] ?? 0) . " BTC";
        } else {
            $errors[] = 'Failed to fetch `getmempoolinfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_mining']) {
        $mininginfo = call_rpc_cached('getmininginfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mininginfo && is_array($mininginfo) && $blockinfo && is_array($blockinfo)) {
            $difficulty = number_format($blockinfo['difficulty'] ?? 0);
            $hashrate = format_hashrate($mininginfo['networkhashps'] ?? 0);
    
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
            $blockcount   = $blockinfo['blocks'] ?? 0;
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
            $mempoolsize = format_bytes($mempoolinfo['bytes'] ?? 0);
        } else {
            $errors[] = 'Failed to fetch `getmempoolinfo`. RPC call returned null or invalid.';
        }
    }
    
    if ($dashboard_config['show_mining']) {
        $mininginfo = call_rpc_cached('getmininginfo', [], $dashboard_config, $url_schema, $ttlOverrides);
    
        if ($mininginfo && is_array($mininginfo) && $blockinfo && is_array($blockinfo)) {
            $difficulty = number_format($blockinfo['difficulty'] ?? 0);
            $hashrate = format_hashrate($mininginfo['networkhashps'] ?? 0);

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
            $blockcount   = $block_count['count'] ?? 0;
            $syncprogress = $getinfo['synchronized'] ? 'Yes' : 'No';
            $chainsize    = round(($getinfo['database_size'] ?? 0) / 1000000000, 2) . " GB";
            $headercount  = number_format($blockcount);
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
            $mempoolsize = format_bytes($mempoolinfo['pool_stats']['bytes_total'] ?? 0);
            $totalfees   = number_format(($mempoolinfo['pool_stats']['fee_total'] ?? 0) / 1e12, 6) . " XMR";
        } else {
            $errors[] = 'Failed to fetch Monero mempool info.';
        }
    }

    if ($dashboard_config['show_mining']) {
        $getinfo = call_rpc_cached('get_info', [], $dashboard_config, $url_schema, $ttlOverrides);
        if ($getinfo) {
            $difficulty      = number_format($getinfo['difficulty'] ?? 0);
            $hashrate        = format_hashrate(($getinfo['difficulty'] ?? 0) / 120);
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

        if ($mininginfo) {
            $currentsupply   = number_format(($mininginfo['already_generated_coins'] ?? 0) / 1000000000000) . " XMR";
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
<html data-theme="<?= htmlspecialchars($theme) ?>">
    <head>
        <meta charset="utf-8">
        <title><?= $coin ?> Node Dashboard</title>
        <meta name="description" content="A simple node dashboard for your <?= $coin ?> node.">
        <meta http-equiv="refresh" content="60">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            /* Theme palettes, applied via the data-theme attribute on <html>.
               The default (no attribute) matches the dark theme. */
            :root,
            html[data-theme="dark"] {
                --bg: #0e0e0e;
                --text: #cfcfcf;
                --section-bg: #121212;
                --section-border: #222;
                --title-color: #fff;
                --title-border: #333;
                --label-color: #aaa;
                --row-border: #2a2a2a;
                --header-bg: #1a1a1a;
                --header-text: #fff;
                --link: #6ea8fe;
            }

            html[data-theme="light"] {
                --bg: #f4f4f4;
                --text: #1c1c1c;
                --section-bg: #ffffff;
                --section-border: #d8d8d8;
                --title-color: #000;
                --title-border: #cccccc;
                --label-color: #555;
                --row-border: #e2e2e2;
                --header-bg: #ffffff;
                --header-text: #000;
                --link: #0d6efd;
            }

            html[data-theme="nord"] {
                --bg: #2e3440;
                --text: #d8dee9;
                --section-bg: #3b4252;
                --section-border: #434c5e;
                --title-color: #eceff4;
                --title-border: #4c566a;
                --label-color: #a0aab9;
                --row-border: #434c5e;
                --header-bg: #3b4252;
                --header-text: #eceff4;
                --link: #88c0d0;
            }

            html[data-theme="solarized"] {
                --bg: #002b36;
                --text: #93a1a1;
                --section-bg: #073642;
                --section-border: #0a4250;
                --title-color: #fdf6e3;
                --title-border: #586e75;
                --label-color: #839496;
                --row-border: #0a4250;
                --header-bg: #073642;
                --header-text: #fdf6e3;
                --link: #2aa198;
            }

            html[data-theme="dracula"] {
                --bg: #282a36;
                --text: #f8f8f2;
                --section-bg: #343746;
                --section-border: #44475a;
                --title-color: #ff79c6;
                --title-border: #44475a;
                --label-color: #bd93f9;
                --row-border: #44475a;
                --header-bg: #343746;
                --header-text: #f8f8f2;
                --link: #8be9fd;
            }

            body {
                margin: 0;
                background: var(--bg);
                color: var(--text);
                font-family: 'Courier New', monospace;
                font-size: 18px;
                padding: 1rem;
            }

            a {
                color: var(--link);
            }

            .section {
                background: var(--section-bg);
                border: 1px solid var(--section-border);
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
                color: var(--title-color);
                margin-bottom: 0.5rem;
                border-bottom: 1px solid var(--title-border);
                padding-bottom: 0.25rem;
            }

            .stat-row {
                display: flex;
                justify-content: space-between;
                padding: 0.15rem 0;
                border-bottom: 1px dotted var(--row-border);
            }

            .stat-row span:first-child {
                color: var(--label-color);
            }

            /* Stats with a tooltip explanation get a help cursor and a subtle hint underline */
            .stat-row span[title] {
                cursor: help;
                text-decoration: underline dotted;
                text-underline-offset: 3px;
            }

            .dashboard-header {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                background-color: var(--header-bg);
                color: var(--header-text);
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
                margin-top: 115px;
            }

            /* Without the switcher row the header is shorter, so reclaim the space */
            .compact-dashboard.no-switcher {
                margin-top: 80px;
            }

            .header-title {
                margin: 0;
                font-size: 18px;
            }

            .theme-switcher {
                margin-top: 6px;
                font-size: 13px;
                font-weight: normal;
            }

            .theme-switcher select {
                background: var(--section-bg);
                color: var(--text);
                border: 1px solid var(--section-border);
                font-family: inherit;
                font-size: 13px;
                padding: 2px 6px;
                border-radius: 3px;
            }

            .dashboard-footer {
                color: var(--header-text);
                font-size: 16px;
                z-index: 1000;
            }
        </style>
        <?php if ($show_theme_switcher): ?>
        <script>
            // Apply the visitor's saved theme before paint to avoid a flash of the
            // server-rendered default. The page auto-refreshes every 60s, so this
            // runs on every load. Only active when the switcher is enabled — with it
            // hidden, the configured theme is final and localStorage is ignored.
            (function () {
                try {
                    var saved = localStorage.getItem('snd-theme');
                    if (saved) {
                        document.documentElement.setAttribute('data-theme', saved);
                    }
                } catch (e) {}
            })();
        </script>
        <?php endif; ?>
    </head>
    <body>
        <div class="dashboard-header">
        <span class="header-title"><?= $coin ?> Node Dashboard</span>
            <br/>
            <span>Updated:</span> <?= date("H:i:s") ?> UTC — <span>Auto-refresh: 60s</span>
            <?php if ($show_theme_switcher): ?>
            <div class="theme-switcher">
                Theme:
                <select id="theme-select" aria-label="Select theme" onchange="setTheme(this.value)">
                    <option value="dark">Dark</option>
                    <option value="light">Light</option>
                    <option value="nord">Nord</option>
                    <option value="solarized">Solarized</option>
                    <option value="dracula">Dracula</option>
                </select>
            </div>
            <?php endif; ?>
        </div>
        <?php if ($show_theme_switcher): ?>
        <script>
            function setTheme(theme) {
                document.documentElement.setAttribute('data-theme', theme);
                try { localStorage.setItem('snd-theme', theme); } catch (e) {}
            }
            // Reflect the currently active theme in the dropdown on load.
            (function () {
                var current = document.documentElement.getAttribute('data-theme') || 'dark';
                var select = document.getElementById('theme-select');
                if (select) select.value = current;
            })();
        </script>
        <?php endif; ?>

        <div class="compact-dashboard<?= $show_theme_switcher ? '' : ' no-switcher' ?>">

        <?php if ($dashboard_config['show_node_info']): ?>
            <div class="section">
                <div class="section-title">Node Info</div>
                <div class="stat-row"><span title="The version of the node software your node is running.">Version</span><span><?= $nodeversion ?></span></div>
                <div class="stat-row"><span title="Which network this node is on (e.g. mainnet, testnet).">Chain</span><span><?= $activechain ?></span></div>
                <?php if ($network !== 'XMR'): ?>
                <div class="stat-row"><span title="Whether the node deletes old block data to save disk space.">Pruned</span><span><?= $ispruned ?></span></div>
                <?php endif; ?>
                <div class="stat-row"><span title="Number of other nodes (peers) this node is connected to.">Connections</span><span><?= $connections ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_blockchain']): ?>
            <div class="section">
                <div class="section-title">Blockchain</div>
                <div class="stat-row"><span title="The number of blocks this node has validated and stored.">Block Height</span><span><?= number_format($blockcount) ?></span></div>
                <div class="stat-row"><span title="The number of block headers the node is aware of. Higher than block height while still syncing.">Block Headers</span><span><?= $headercount?></span></div>
                <?php if ($network !== 'XMR'): ?>
                <div class="stat-row"><span title="How far the node has progressed verifying the blockchain. 100% means fully synced.">Sync Progress</span><span><?= $syncprogress ?></span></div>
                <?php endif; ?>
                <?php if ($network == 'XMR'): ?>
                <div class="stat-row"><span title="Whether the node is fully synced with the network.">Synced</span><span><?= $syncprogress ?></span></div>
                <?php endif; ?>
                <div class="stat-row"><span title="Disk space currently used by the blockchain data.">Chain Size</span><span><?= $chainsize ?></span></div>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_mempool']): ?>
            <div class="section">
                <div class="section-title">Mempool</div>
                <div class="stat-row"><span title="Unconfirmed transactions waiting in the mempool to be included in a block.">Pending TX Count</span><span><?= $mempooltxns ?></span></div>
                <div class="stat-row"><span title="Total size of all unconfirmed transactions currently in the mempool.">Total Size</span><span><?= $mempoolsize ?></span></div>
                <?php if ($network == 'BTC' || $network == 'XMR'): ?>
                <div class="stat-row"><span title="Combined fees of all unconfirmed transactions in the mempool.">Total Fees</span><span><?= $totalfees ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_mining']): ?>
            <div class="section">
                <div class="section-title">Mining</div>
                <div class="stat-row"><span title="A measure of how hard it is to mine a new block. Adjusts to keep block times steady.">Difficulty</span><span><?= $difficulty ?></span></div>
                <div class="stat-row"><span title="Estimated total computing power miners are dedicating to the network.">Hash Rate</span><span><?= $hashrate ?></span></div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span title="Blocks remaining until the next mining difficulty adjustment (every 2016 blocks).">Blocks till retarget</span><span><?= $retarget ?></span></div>
                <div class="stat-row"><span title="Blocks remaining until the next halving, when the block reward is cut in half.">Blocks till halving</span><span><?= $nexthalving ?></span></div>
                <div class="stat-row"><span title="The amount of newly minted coin paid to a miner for finding a block.">Block Reward</span><span><?= $subsidy ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_transactions']): ?>
            <div class="section">
                <div class="section-title">Transactions</div>
                <div class="stat-row"><span title="The all-time total number of transactions recorded on the blockchain.">Total Transactions</span><span><?= $totaltxns ?></span></div>
                <div class="stat-row"><span title="The total amount of coin mined into existence so far.">Current Supply</span><span><?= $currentsupply ?></span></div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span title="Average number of transactions per second over the last 30 days.">Average tx/s (30-days)</span><span><?= $averagetxns ?></span></div>
                <div class="stat-row"><span title="Number of transactions confirmed in the last 30 days.">30-day transactions</span><span><?= $monthlytxns ?></span></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($dashboard_config['show_fees']): ?>
            <div class="section">
                <div class="section-title">Transaction Feerates (<?= $ratename ?>)</div>
                <?php if ($network !== "XMR"): ?>
                <div class="stat-row"><span title="Estimated fee rate to get confirmed within the next block (fastest).">1 block</span><span><?= $fastfees ?></span></div>
                <div class="stat-row"><span title="Estimated fee rate to get confirmed within about 6 blocks (~1 hour).">6 blocks</span><span><?= $mediumfees ?></span></div>
                <div class="stat-row"><span title="Estimated fee rate to get confirmed within about 144 blocks (~1 day, cheapest).">144 blocks</span><span><?= $slowfees ?></span></div>
                <?php elseif ($network == "XMR"): ?>
                <div class="stat-row"><span title="Higher fee for faster confirmation.">Fast</span><span><?= $fastfees ?></span></div>
                <div class="stat-row"><span title="Balanced fee for typical confirmation times.">Medium</span><span><?= $mediumfees ?></span></div>
                <div class="stat-row"><span title="Lower fee with slower confirmation.">Slow</span><span><?= $slowfees ?></span></div>
                <div class="stat-row"><span title="Lowest fee with the slowest confirmation.">Slowest</span><span><?= $slowestfees ?></span></div>
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
                <span class="footer-title">Made by <a href="https://tech1k.com">Tech1k</a> | <a href="https://github.com/Tech1k/simple-node-dashboard">Source Code</a></span>
            </center>
        </div>
    </body>
</html>