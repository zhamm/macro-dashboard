# Macro Risk Dashboard (Watchdog)

A PHP-based economic warning dashboard that monitors 10 key macroeconomic indicators in real time to assess systemic market risk. The dashboard uses a traffic-light system (Green / Orange / Red) and recommends portfolio allocation strategies based on how many indicators are flashing red.

## What It Does

- **Fetches live data** from FRED (Federal Reserve Economic Data) and Alpha Vantage APIs
- **Evaluates 10 indicators** against predefined caution, fear, and crisis thresholds
- **Displays an overall risk level** based on the number of red flags:
  - **0–2 Red** → Stable — Growth strategy (80% stocks / 20% bonds)
  - **3–4 Red** → Caution — Vigilant strategy (70% stocks / 30% cash)
  - **5–6 Red** → Warning — Reduce exposure (50/50 split)
  - **7–10 Red** → Critical — Defensive mode (30% stocks / 70% bonds & cash)
- **Auto-refreshes every 4 hours** with a visible countdown timer and a manual refresh button
- Falls back to hardcoded default values when an API call fails, marking the data as **stale**

## Indicators Tracked

| # | Indicator | Source | Frequency |
|---|-----------|--------|-----------|
| 1 | USD/JPY Exchange Rate | Alpha Vantage FX | Daily |
| 2 | US 10-Year Treasury Yield | FRED (`DGS10`) | Daily |
| 3 | High Yield Credit Spreads | FRED (`BAMLH0A0HYM2`) | Weekly |
| 4 | Regional Bank ETF (KRE) | Alpha Vantage Quote | Daily |
| 5 | China Manufacturing PMI | FRED (`CHPMINDMANPMI`) | Monthly |
| 6 | VIX (Volatility Index) | FRED (`VIXCLS`) | Daily |
| 7 | US Unemployment Rate | FRED (`UNRATE`) | Monthly |
| 8 | CRE Delinquency Rate | FRED (`DRCCLACBS`) | Quarterly |
| 9 | Fed Funds Rate | FRED (`FEDFUNDS`) | Per Meeting |
| 10 | Buffett Indicator (Market Cap / GDP) | FRED (`NCBEILQ027S`, `GDP`) | Quarterly |

## File Structure

```
watchdog/
├── config.php      # API keys (FRED_API_KEY, ALPHA_VANTAGE_KEY)
├── index.php       # Main dashboard – live API data
├── simulated.php   # Demo/simulated version with randomized data
├── test.php        # Test utilities
└── README.md
```

## Requirements

### Server

- **PHP 7.4+** (tested up to 8.x)
- **cURL extension** (`php_curl`) — used for all external API calls
- **JSON extension** (`php_json`) — enabled by default in most PHP installs
- A web server (Apache, Nginx, or PHP's built-in server)

### API Keys

You need free API keys from two services:

1. **FRED API** — [https://fred.stlouisfed.org/docs/api/api_key.html](https://fred.stlouisfed.org/docs/api/api_key.html)
2. **Alpha Vantage** — [https://www.alphavantage.co/support/#api-key](https://www.alphavantage.co/support/#api-key)

### Frontend (loaded via CDN, no install needed)

- Bootstrap 5.3
- Bootstrap Icons 1.11

## Setup

1. **Clone or copy** the project files to your web server's document root.

2. **Create `config.php`** in the project root with your API keys:

   ```php
   <?php
   define('FRED_API_KEY', 'your_fred_api_key_here');
   define('ALPHA_VANTAGE_KEY', 'your_alpha_vantage_key_here');
   ```

3. **Start the server.** Using PHP's built-in server for local dev:

   ```bash
   php -S localhost:8000
   ```

4. **Open the dashboard** at [http://localhost:8000/index.php](http://localhost:8000/index.php).

## Debug Mode

Append `?debug=1` to the URL to see a debug panel at the bottom of the page with API response times, HTTP status codes, and error details:

```
http://localhost:8000/index.php?debug=1
```

## Simulated Mode

Open `simulated.php` instead of `index.php` to see the dashboard with randomized data (no API keys required). Useful for UI development and demos.

## Disclaimer

This tool is for **educational and monitoring purposes only**. It does not constitute financial advice. Always consult with a certified financial advisor before making investment decisions based on market indicators.
