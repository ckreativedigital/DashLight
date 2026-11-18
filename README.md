# DashLights

Plain-English status lights for the WordPress dashboard. DashLights checks four things once a day and shows each as green, yellow, or red:

- **Domain renewal:** when the domain expires, looked up through the registry's public RDAP service
- **Security certificate:** when the site's SSL certificate expires
- **Updates:** WordPress, plugin, and theme updates waiting
- **Backups:** when the last backup ran (UpdraftPlus for now; others can report through a filter)

It also emails the site owner 30 and 7 days before the domain expires, and 7 days and 1 day before the certificate expires.

No account, no tracking, nothing sent to us. Made by [CKreative Digital](https://ckreativedigital.com/).

## Requirements

WordPress 6.4+, PHP 7.4+.

## Install

Download the latest release zip and upload it in **Plugins → Add New → Upload Plugin**, or copy this folder to `wp-content/plugins/dashlights/`.

## For developers

Report a backup DashLights can't see on its own:

```php
add_filter( 'dashlights_backups', function ( $found ) {
	$found[] = array(
		'time'   => my_last_backup_timestamp(),
		'source' => 'My Backup Tool',
	);
	return $found;
} );
```

Stored data: `dashlights_settings` and `dashlights_data` options, the `dashlights_rdap_bootstrap` transient, and the `dashlights_daily` scheduled event. All are removed when the plugin is deleted.

## License

GPLv2 or later.
