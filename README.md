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


## Security Notes

- Make sure to protect the dashboard if you’re displaying sensitive node info.
- Use firewall rules or other methods to restrict access, especially for public deployments.


## License

This project is released under the [MIT License](https://opensource.org/licenses/MIT). See the LICENSE file for more details.
