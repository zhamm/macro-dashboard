<?php
/* =========================================================
   Macro Risk Dashboard – Production Safe Version
   ========================================================= */

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
set_time_limit(60);

/* ================= CONFIG ================= */

require_once __DIR__ . '/config.php';

if (!defined('FRED_API_KEY') || !defined('ALPHA_VANTAGE_KEY')) {
    die('FATAL: API keys not loaded from config.php');
}

define('API_TIMEOUT', 5);
define('API_CONNECT_TIMEOUT', 3);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';
$debugLog = [];

/* ================= DEBUG ================= */

function dbg($label, $data = null) {
    global $DEBUG, $debugLog;
    if ($DEBUG) {
        $debugLog[] = [
            'time' => date('H:i:s'),
            'label' => $label,
            'data' => $data
        ];
    }
}

/* ================= HTTP ================= */

function apiGetJson(string $url, string $tag): ?array {
    $t0 = microtime(true);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => API_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT => API_TIMEOUT,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'MacroRiskDashboard/1.0'
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err   = curl_error($ch);
    $http  = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $ms = round((microtime(true) - $t0) * 1000);

    dbg("API $tag", [
        'http' => $http,
        'errno' => $errno,
        'error' => $err,
        'ms' => $ms
    ]);

    if ($errno !== 0 || $raw === false || $http < 200 || $http >= 300) {
        return null;
    }

    $json = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        dbg("JSON ERROR $tag", json_last_error_msg());
        return null;
    }

    return $json;
}

/* ================= DATA PROVIDERS ================= */

function fredLatest(string $series): ?float {
    $url = "https://api.stlouisfed.org/fred/series/observations"
         . "?series_id=" . urlencode($series)
         . "&api_key=" . urlencode(FRED_API_KEY)
         . "&file_type=json&sort_order=desc&limit=1";

    $json = apiGetJson($url, "FRED_$series");
    if (!$json) return null;

    $v = $json['observations'][0]['value'] ?? null;
    return ($v !== null && $v !== '.' && is_numeric($v)) ? (float)$v : null;
}

function getUsdJpy(): ?float {
    $url = "https://www.alphavantage.co/query"
         . "?function=FX_DAILY&from_symbol=USD&to_symbol=JPY"
         . "&apikey=" . urlencode(ALPHA_VANTAGE_KEY);

    $json = apiGetJson($url, 'ALPHA_USDJPY');
    if (!$json || !isset($json['Time Series FX (Daily)'])) return null;

    $latest = reset($json['Time Series FX (Daily)']);
    return isset($latest['4. close']) ? (float)$latest['4. close'] : null;
}

function getKrePrice(): ?float {
    $url = "https://www.alphavantage.co/query"
         . "?function=GLOBAL_QUOTE&symbol=KRE"
         . "&apikey=" . urlencode(ALPHA_VANTAGE_KEY);

    $json = apiGetJson($url, 'ALPHA_KRE');
    return isset($json['Global Quote']['05. price'])
        ? (float)$json['Global Quote']['05. price']
        : null;
}

function getBuffettIndicator(): ?float {
    $mcap = fredLatest('NCBEILQ027S');
    $gdp  = fredLatest('GDP');
    return ($mcap && $gdp) ? round(($mcap / $gdp) * 100, 1) : null;
}

/* ================= DISPATCH ================= */

function fetchRealTimeData(string $key): ?float {
    switch ($key) {
        case 'JPY_USD':           return getUsdJpy();
        case 'US_TY_10Y':         return fredLatest('DGS10');
        case 'HIGH_YIELD_SPREAD': return fredLatest('BAMLH0A0HYM2');
        case 'KRE':               return getKrePrice();
        case 'CHINA_PMI':         return fredLatest('CHPMINDMANPMI');
        case 'VIX':               return fredLatest('VIXCLS');
        case 'UNEMPLOYMENT':      return fredLatest('UNRATE');
        case 'CRE_DELINQUENCY':   return fredLatest('DRCCLACBS');
        case 'FED_FUNDS':         return fredLatest('FEDFUNDS');
        case 'BUF_FITZ':          return getBuffettIndicator();
        default:                  return null;
    }
}

/* ================= INDICATORS ================= */

$indicators = [
    'JPY_USD' => [
        'name'=>'USD/JPY Exchange Rate','current'=>153.5,
        'threshold_caution'=>140,'threshold_fear'=>130,'threshold_crisis'=>120,
        'freq'=>'Daily','desc'=>'Carry Trade Unwinding'
    ],
    'US_TY_10Y' => [
        'name'=>'US 10Y Treasury','current'=>4.35,
        'threshold_caution'=>5,'threshold_fear'=>5.5,'threshold_crisis'=>6,
        'freq'=>'Daily','desc'=>'Bond Market Stress'
    ],
    'HIGH_YIELD_SPREAD' => [
        'name'=>'High Yield Spread','current'=>2.86,
        'threshold_caution'=>4,'threshold_fear'=>6,'threshold_crisis'=>8,
        'freq'=>'Weekly','desc'=>'Credit Risk'
    ],
    'KRE' => [
        'name'=>'Regional Banks (KRE)','current'=>45.2,'baseline'=>56.5,
        'freq'=>'Daily','desc'=>'Banking Stability'
    ],
    'CHINA_PMI' => [
        'name'=>'China Manufacturing PMI','current'=>48.5,
        'threshold_caution'=>45,'threshold_fear'=>40,'threshold_crisis'=>35,
        'freq'=>'Monthly','desc'=>'Global Demand'
    ],
    'VIX' => [
        'name'=>'VIX','current'=>18.6,
        'threshold_caution'=>25,'threshold_fear'=>35,'threshold_crisis'=>50,
        'freq'=>'Daily','desc'=>'Market Fear'
    ],
    'UNEMPLOYMENT' => [
        'name'=>'US Unemployment','current'=>4.4,
        'threshold_caution'=>5,'threshold_fear'=>6,'threshold_crisis'=>7,
        'freq'=>'Monthly','desc'=>'Labor Market'
    ],
    'CRE_DELINQUENCY' => [
        'name'=>'CRE Delinquency','current'=>1.57,
        'threshold_caution'=>3,'threshold_fear'=>7.29,'threshold_crisis'=>10,
        'freq'=>'Quarterly','desc'=>'Commercial Property Stress'
    ],
    'FED_FUNDS' => [
        'name'=>'Fed Funds Rate','current'=>3.63,
        'threshold_caution'=>2,'threshold_fear'=>1,'threshold_crisis'=>0,
        'freq'=>'Meeting','desc'=>'Monetary Policy'
    ],
    'BUF_FITZ' => [
        'name'=>'Buffett Indicator','current'=>221,
        'threshold_caution'=>150,'threshold_fear'=>200,'threshold_crisis'=>250,
        'freq'=>'Quarterly','desc'=>'Market Valuation'
    ],
];

/* ================= PROCESS ================= */

$statusData = [];
$redFlags = 0;

foreach ($indicators as $key => $cfg) {
    $value = fetchRealTimeData($key);
    $stale = false;

    if (!is_numeric($value)) {
        $value = $cfg['current'];
        $stale = true;
    }

    $status = 'Green';

    if ($key === 'KRE') {
        $drop = (($cfg['baseline'] - $value) / $cfg['baseline']) * 100;
        if ($drop >= 30) $status = 'Red';
        elseif ($drop >= 20) $status = 'Orange';
    } elseif ($key === 'CHINA_PMI') {
        if ($value < $cfg['threshold_fear']) $status = 'Red';
        elseif ($value < $cfg['threshold_caution']) $status = 'Orange';
    } else {
        if ($value >= $cfg['threshold_fear']) $status = 'Red';
        elseif ($value >= $cfg['threshold_caution']) $status = 'Orange';
    }

    if ($status === 'Red') $redFlags++;

    $statusData[$key] = [
        'name'=>$cfg['name'],
        'value'=>$value,
        'status'=>$status,
        'freq'=>$cfg['freq'],
        'desc'=>$cfg['desc'],
        'stale'=>$stale
    ];
}

/* ================= DASHBOARD STATE ================= */

if ($redFlags >= 7) {
    $dashboardStatus = "CRITICAL – DEFENSIVE MODE";
    $dashboardMessage = "Systemic risk elevated. Reduce equity exposure immediately.";
    $alertColor = "bg-danger text-white";
    $alertIcon = "bi bi-exclamation-triangle-fill";
} elseif ($redFlags >= 5) {
    $dashboardStatus = "WARNING – REDUCE EXPOSURE";
    $dashboardMessage = "Market stress rising. Consider lowering risk.";
    $alertColor = "bg-warning text-dark";
    $alertIcon = "bi bi-exclamation-diamond-fill";
} elseif ($redFlags >= 3) {
    $dashboardStatus = "CAUTION – BE VIGILANT";
    $dashboardMessage = "Volatility risk increasing.";
    $alertColor = "bg-info text-dark";
    $alertIcon = "bi bi-eye-fill";
} else {
    $dashboardStatus = "STABLE – NORMAL CONDITIONS";
    $dashboardMessage = "Market conditions remain stable.";
    $alertColor = "bg-success text-white";
    $alertIcon = "bi bi-shield-check";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Macro Risk Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; padding-top: 20px; }
        .indicator-card { transition: transform 0.2s; height: 100%; }
        .indicator-card:hover { transform: translateY(-5px); }
        .status-badge { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 1px; }
        .dashboard-header { background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%); color: white; border-radius: 10px 10px 0 0; padding: 2rem; }
        .critical-bg { background-color: #dc3545; color: white; }
        .warning-bg { background-color: #ffc107; color: black; }
        .caution-bg { background-color: #0dcaf0; color: black; }
        .stable-bg { background-color: #198754; color: white; }
    </style>
</head>
<body>

    <div class="container">

        <!-- Header & Executive Summary -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card shadow-lg">
                    <div class="card-body p-0">
                        <div class="dashboard-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h1 class="display-5 fw-bold mb-0"><i class="bi bi-graph-up-arrow"></i> Macro Risk Dashboard</h1>
                                    <p class="mb-3 opacity-75">Real-time monitoring of 10 warning signs for economic downturns</p>
                                </div>
                                <div class="text-end">
                                    <small class="text-uppercase opacity-75">Last Updated</small><br>
                                    <span class="fw-bold fs-5"><?php echo date("Y-m-d H:i:s"); ?></span><br>
                                    <small class="opacity-75">Next refresh in <span id="countdown"></span></small><br>
                                    <button class="btn btn-sm btn-outline-light mt-2" onclick="location.reload();"><i class="bi bi-arrow-clockwise me-1"></i>Refresh Now</button>
                                </div>
                            </div>
                        </div>

                        <!-- Alert Box -->
                        <div class="p-4">
                            <div class="alert <?php echo $alertColor; ?> alert-dismissible fade show shadow-sm rounded-0" role="alert">
                                <h4 class="alert-heading fw-bold mb-1"><i class="<?php echo $alertIcon; ?> me-2"></i><?php echo $dashboardStatus; ?></h4>
                                <p class="mb-0 fw-medium"><?php echo $dashboardMessage; ?></p>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>

                            <!-- Action Plan -->
                            <div class="row mt-4 mt-md-3">
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light">
                                        <div class="card-body">
                                            <h5 class="card-title"><i class="bi bi-list-check"></i> Current Red Flags: <span class="text-danger fw-bold fs-3"><?php echo $redFlags; ?> / 10</span></h5>
                                            <div class="progress mt-2" style="height: 10px;">
                                                <div class="progress-bar bg-danger" role="progressbar" style="width: <?php echo ($redFlags * 10); ?>%"></div>
                                            </div>
                                            <p class="small mt-2 text-muted">Thresholds: 3+ Caution | 5+ Reduce Exposure | 7+ Defensive</p>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card border-0 bg-light h-100">
                                        <div class="card-body">
                                            <h5 class="card-title"><i class="bi bi-people"></i> Recommended Allocation</h5>
                                            <ul class="list-unstyled mt-3">
                                                <?php if ($redFlags >= 7): ?>
                                                    <li class="mb-2"><strong class="text-danger">Defensive Strategy:</strong> 30% Stocks / 70% Bonds & Cash</li>
                                                <?php elseif ($redFlags >= 5): ?>
                                                    <li class="mb-2"><strong class="text-warning">Caution Strategy:</strong> 50% Stocks / 50% Bonds & Cash</li>
                                                <?php elseif ($redFlags >= 3): ?>
                                                    <li class="mb-2"><strong class="text-info">Vigilant Strategy:</strong> 70% Stocks / 30% Cash Reserve</li>
                                                <?php else: ?>
                                                    <li class="mb-2"><strong class="text-success">Growth Strategy:</strong> 80% Stocks / 20% Bonds</li>
                                                <?php endif; ?>
                                                <li class="mb-2"><i class="bi bi-shield-check text-success me-2"></i> Maintain 6 months Emergency Fund</li>
                                                <li class="mb-2"><i class="bi bi-coin text-warning me-2"></i> Consider Gold/Diversification</li>
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Indicators Grid -->
        <div class="row">
            <?php foreach ($statusData as $key => $d):
                $colorClass = ($d['status'] === 'Red' ? 'text-danger fw-bold' : ($d['status'] === 'Orange' ? 'text-warning fw-bold' : 'text-success'));
                $bgClass = ($d['status'] === 'Red' ? 'bg-danger bg-opacity-10' : ($d['status'] === 'Orange' ? 'bg-warning bg-opacity-10' : 'bg-success bg-opacity-10'));
                $badgeClass = ($d['status'] === 'Red' ? 'bg-danger' : ($d['status'] === 'Orange' ? 'bg-warning text-dark' : 'bg-success'));
                $badgeLabel = ($d['status'] === 'Green' ? 'Normal' : ($d['status'] === 'Orange' ? 'Caution' : 'Critical'));
                $iconClass = ($d['status'] === 'Red' ? 'bi-exclamation-triangle-fill text-danger' : ($d['status'] === 'Orange' ? 'bi-exclamation-circle-fill text-warning' : 'bi-check-circle-fill text-success'));
                $unit = '';
                if ($key === 'JPY_USD') $unit = ' JPY';
                elseif ($key === 'US_TY_10Y' || $key === 'UNEMPLOYMENT' || $key === 'CRE_DELINQUENCY' || $key === 'HIGH_YIELD_SPREAD' || $key === 'FED_FUNDS') $unit = '%';
                elseif ($key === 'KRE') $unit = ' USD';
                elseif ($key === 'BUF_FITZ') $unit = '%';
            ?>
            <div class="col-md-4 mb-4">
                <div class="card indicator-card shadow-sm h-100 border-0 <?php echo $bgClass; ?>">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="m-0 fw-bold"><?php echo $d['name']; ?></h5>
                        <span class="status-badge <?php echo $badgeClass; ?> badge">
                            <?php echo $badgeLabel; ?>
                        </span>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-8">
                                <div class="fs-3 <?php echo $colorClass; ?>">
                                    <?php echo $d['value'] . $unit; ?>
                                    <?php if ($d['stale']): ?>
                                        <span class="badge bg-secondary fs-6">stale</span>
                                    <?php endif; ?>
                                </div>
                                <small class="text-muted"><i class="bi bi-calendar me-1"></i> <?php echo $d['freq']; ?> Data</small>
                            </div>
                            <div class="col-4 text-center">
                                <i class="bi <?php echo $iconClass; ?> fs-1"></i>
                            </div>
                        </div>
                        <hr class="my-3">
                        <p class="card-text small text-muted mb-0">
                            <strong>Warning Sign:</strong> <?php echo $d['desc']; ?>
                        </p>
                        <p class="small text-muted mt-1">
                            <strong>Thresholds:</strong> Caution @
                            <?php
                                if ($key === 'KRE') {
                                    echo '20% Drop';
                                } else {
                                    echo $indicators[$key]['threshold_caution'] ?? 'N/A';
                                    if (in_array($key, ['US_TY_10Y','HIGH_YIELD_SPREAD','UNEMPLOYMENT','CRE_DELINQUENCY','FED_FUNDS','BUF_FITZ'])) echo '%';
                                    elseif ($key === 'JPY_USD') echo ' JPY';
                                }
                            ?>
                        </p>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Data Source Info -->
        <div class="row mt-5 mb-5">
            <div class="col-12">
                <div class="card border-info bg-light">
                    <div class="card-header bg-info bg-opacity-10 text-info">
                        <h5 class="mb-0"><i class="bi bi-database-fill"></i> Data Source Details</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-1">This dashboard fetches live data from external financial APIs. When an API call fails or times out, the most recent cached value is used and marked as <span class="badge bg-secondary">stale</span>.</p>
                        <ul class="small text-muted">
                            <li>Exchange Rates: Alpha Vantage FX API</li>
                            <li>Treasury Yields, Spreads, VIX, Unemployment: FRED (Federal Reserve Economic Data)</li>
                            <li>Stock Indices (KRE): Alpha Vantage Global Quote</li>
                            <li>Buffett Indicator: Calculated from FRED (Market Cap / GDP)</li>
                        </ul>
                        <hr>
                        <div class="alert alert-warning small" role="alert">
                            <strong>Financial Disclaimer:</strong> This tool is for educational and monitoring purposes only. It does not constitute financial advice. Always consult with a certified financial advisor before making investment decisions based on market indicators.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($DEBUG): ?>
        <div class="row mb-5">
            <div class="col-12">
                <div class="card border-secondary">
                    <div class="card-header bg-secondary text-white"><h5 class="mb-0"><i class="bi bi-bug"></i> Debug Log</h5></div>
                    <div class="card-body"><pre class="mb-0"><?php print_r($debugLog); ?></pre></div>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-refresh every 4 hours (14400 seconds)
        const REFRESH_INTERVAL = 4 * 60 * 60; // seconds
        let secondsLeft = REFRESH_INTERVAL;

        function pad(n) { return String(n).padStart(2, '0'); }

        function updateCountdown() {
            const h = Math.floor(secondsLeft / 3600);
            const m = Math.floor((secondsLeft % 3600) / 60);
            const s = secondsLeft % 60;
            document.getElementById('countdown').textContent = pad(h) + ':' + pad(m) + ':' + pad(s);
            if (secondsLeft <= 0) {
                location.reload();
            }
            secondsLeft--;
        }

        setInterval(updateCountdown, 1000);
        updateCountdown();
    </script>
</body>
</html>
