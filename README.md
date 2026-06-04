# Simple Node Dashboard

A lightweight, self-hosted dashboard that displays information from your Bitcoin, Litecoin, Monero, or other Bitcoin Core-compatible node.


## Features

- Clean display of essential node and network statistics
- Built-in support for Bitcoin, Litecoin, and Monero, plus any Bitcoin Core-compatible coin via custom network configs
- Filesystem-based caching to reduce RPC I/O
- Minimal and easy setup
- Fully responsive for desktop and mobile devices
- Light and dark mode with several built-in themes (Dark, Light, Nord, Solarized, Dracula), remembered across visits
- Configurable default theme, with the option to lock it and hide the switcher for fixed/branded deployments
- Tooltip explanations for every stat (hover over a stat label)


## Installation

1. Place `index.php` in a PHP-enabled web directory.
2. Ensure the web server has read/write access to the directory for the cache files.
3. Configure your nodes with the correct RPC credentials and IP allowlist.
4. Update the configuration section at the top of `index.php` to match your node's configuration.
5. Navigate to `index.php` in a web browser of your choice.


## Docker

The dashboard can also be run as a container. Configuration is provided through environment variables, so no code changes are needed.

Prebuilt multi-arch images (`linux/amd64` and `linux/arm64`) are published on Docker Hub and the GitHub Container Registry:

```sh
docker pull techtoshi/simple-node-dashboard
# or
docker pull ghcr.io/tech1k/simple-node-dashboard

docker run -d \
  --name=simple-node-dashboard \
  --restart=unless-stopped \
  -p 80:80 \
  -e NETWORK=XMR \
  -e NODE_IP=127.0.0.1 \
  -e RPC_PORT=18081 \
  techtoshi/simple-node-dashboard
```

To build the image yourself instead:

```sh
docker build -t simple-node-dashboard .
```

Supported environment variables (all optional; the defaults match the configuration at the top of `index.php`):

| Variable | Default | Description |
|----------|---------|-------------|
| `NETWORK` | `CHANGE_ME` | Network to display: `BTC`, `LTC`, `XMR`, or a custom ticker (see [Custom Networks](#custom-networks)) |
| `NODE_IP` | `CHANGE_ME` | Node IP address (usually `127.0.0.1`) |
| `RPC_PORT` | `CHANGE_ME` | RPC port (BTC: 8332 \| LTC: 9332 \| XMR: 18081) |
| `RPC_USER` | `CHANGE_ME` | RPC username (not usually needed for XMR) |
| `RPC_PASS` | `CHANGE_ME` | RPC password (not usually needed for XMR) |
| `SHOW_NODE_INFO` | `true` | Show the Node Info section |
| `SHOW_BLOCKCHAIN` | `true` | Show the Blockchain section |
| `SHOW_MEMPOOL` | `true` | Show the Mempool section |
| `SHOW_MINING` | `true` | Show the Mining section |
| `SHOW_TRANSACTIONS` | `true` | Show the Transactions section |
| `SHOW_FEES` | `true` | Show the Transaction Feerates section |
| `THEME` | `dark` | Default theme: `dark`, `light`, `nord`, `solarized`, or `dracula` |
| `SHOW_THEME_SWITCHER` | `true` | Set `false` to lock the theme and hide the switcher |


## Custom Networks

Beyond the built-in `BTC`, `LTC`, and `XMR`, the dashboard works with any
**Bitcoin Core-compatible** coin (Bitcoin Cash, Dash, Digibyte, etc.) that speaks the standard Bitcoin JSON-RPC. There are two ways to add one.

**1. Add an entry to the `$networks` array** near the top of `index.php`:

```php
'BCH' => ['name' => 'Bitcoin Cash', 'family' => 'bitcoin', 'unit' => 'BCH', 'halving_interval' => 210000, 'initial_subsidy' => 50, 'fee_unit' => 'sat/vB', 'decimals' => 8],
```

Then set `network` (or the `NETWORK` env var) to `BCH`.

**2. Define one via environment variables** (handy for Docker). Set `NETWORK` to the
ticker, plus any of the `CUSTOM_*` overrides below:

```sh
docker run -d -p 80:80 \
  -e NETWORK=BCH \
  -e NODE_IP=127.0.0.1 \
  -e RPC_PORT=8332 \
  -e CUSTOM_NAME="Bitcoin Cash" \
  -e CUSTOM_UNIT=BCH \
  -e CUSTOM_HALVING_INTERVAL=210000 \
  -e CUSTOM_INITIAL_SUBSIDY=50 \
  -e CUSTOM_FEE_UNIT=sat/vB \
  -e CUSTOM_DECIMALS=8 \
  techtoshi/simple-node-dashboard
```

| Variable | Default | Description |
|----------|---------|-------------|
| `CUSTOM_NAME` | the ticker | Display name (e.g. "Bitcoin Cash") |
| `CUSTOM_UNIT` | the ticker | Currency unit shown alongside amounts |
| `CUSTOM_HALVING_INTERVAL` | `210000` | Blocks between reward halvings |
| `CUSTOM_INITIAL_SUBSIDY` | `50` | Initial block reward |
| `CUSTOM_FEE_UNIT` | `sat/vB` | Label for the fee-rate column |
| `CUSTOM_DECIMALS` | `8` | Decimal places shown for the mempool total-fee amount |

> The supply, block-reward, and next-halving stats assume a Bitcoin-style halving
> schedule. Coins with a different emission model will show those particular figures
> inaccurately; block height, sync status, mempool, fees, and hashrate are unaffected.

Monero is handled as a special case and cannot be used as a template for custom coins.


## Security Notes

- Make sure to protect the dashboard if you’re displaying sensitive node info.
- Use firewall rules or other methods to restrict access, especially for public deployments.


## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT). See the LICENSE file for more details.
