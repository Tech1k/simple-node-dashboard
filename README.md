# Simple Node Dashboard

A lightweight, self-hosted dashboard that displays information from your Bitcoin, Litecoin, or Monero node.


## Demos

- [Litecoin Node Dashboard](https://ltc.librenode.com/dashboard)
- [Monero Node Dashboard](https://xmr.librenode.com/dashboard)


## Features

- Clean display of essential node and network statistics
- Filesystem-based caching to reduce RPC I/O
- Minimal and easy setup
- Fully responsive for desktop and mobile devices


## Planned Features

- Light/dark mode
- Custom themes
- Tooltip explanations for stats


## Installation

1. Place `index.php` in a PHP-enabled web directory.
2. Ensure the web server has read/write access to the directory for the cache files.
3. Configure your nodes with the correct RPC credentials and IP allowlist.
4. Update the confguration section at the top of `index.php` to match your node's configuration.
5. Navigate to `index.php` in a web browser of your choice.


## Security Notes

- Make sure to protect the dashboard if you’re displaying sensitive node info.
- Use firewall rules or other methods to restrict access, especially for public deployments.


## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT). See the LICENSE file for more details.
