# Simple Node Dashboard

A lightweight, self-hosted dashboard that displays information from your Bitcoin, Litecoin, or Monero node.


## Features

- Clean display of essential node and network statistics
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
| `NETWORK` | `CHANGE_ME` | Network to display: `BTC`, `LTC`, or `XMR` |
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


## Security Notes

- Make sure to protect the dashboard if you’re displaying sensitive node info.
- Use firewall rules or other methods to restrict access, especially for public deployments.


## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT). See the LICENSE file for more details.
