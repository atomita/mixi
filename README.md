# Mixi

Mixi API for Laravel 4



## Installation

Add `atomita/mixi` to `composer.json`.
```
"atomita/mixi": "dev-master"
```
    
Run `composer update` to pull down the latest version of Mixi.

Now open up `app/config/app.php` and add the service provider to your `providers` array.
```php
'providers' => array(
	'Atomita\Mixi\MixiServiceProvider',
)
```

Now add the alias.
```php
'aliases' => array(
	'Mixi' => 'Atomita\Mixi\MixiFacade',
)
```


## Configuration

Run `php artisan config:publish atomita/mixi` and modify the config file with your own informations.


