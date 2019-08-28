# General
This plugin for wordpress is based on my class for faking mod_sendfile via symlinks

# Installation
Really simple: Just place the files in a directory with the name "simulatesendfile" in your wordpress plugin-directory.

## Additional informations for some plugins
Some plugins search explicitly for the "mod_sendfile"-module in apache.
In most cases (PHP running NOT as module) you can include or copy the contents from "dirtyhacks.php" in the "functions.php" of your theme.
Known plugins are currently:
- WooCommerce

# Configuration
Currently there are some options that can be set via database or a respective plugin

option name | description | default
--- | --- | ---
simulatesendfile_dir | directory where symlinks are stored | WP_CONTENT_URL '/symlinks'
simulatesendfile_expire | time in seconds a symlink is removed | 3600
