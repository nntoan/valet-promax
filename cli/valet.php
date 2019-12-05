#!/usr/bin/env php
<?php

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__.'/../vendor/autoload.php')) {
    require __DIR__.'/../vendor/autoload.php';
} elseif (file_exists(__DIR__.'/../../../autoload.php')) {
    require __DIR__.'/../../../autoload.php';
} else {
    require getenv('HOME').'/.composer/vendor/autoload.php';
}

use Silly\Application;
use Illuminate\Container\Container;
use function Valet\info;
use function Valet\output;
use function Valet\table;
use function Valet\warning;

/**
 * Relocate config dir to ~/.config/valet/ if found in old location.
 */
if (is_dir(VALET_LEGACY_HOME_PATH) && !is_dir(VALET_HOME_PATH)) {
    Configuration::createConfigurationDirectory();
}

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = '2.5.4';

$app = new Application('Laravel Valet', $version);

/**
 * Prune missing directories and symbolic links on every command.
 */
if (is_dir(VALET_HOME_PATH)) {
    Configuration::prune();

    Site::pruneLinks();
}

/**
 * Install Valet and any required services.
 */
$app->command('install', function () {
    Nginx::stop();

    Configuration::install();
    Nginx::install();
    PhpFpm::install();
    DnsMasq::install(Configuration::read()['tld']);
    Nginx::restart();
    Valet::symlinkToUsersBin();

    output(PHP_EOL.'<info>Valet installed successfully!</info>');
})->descriptions('Install the Valet services');

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Upgrade helper: ensure the tld config exists
     */
    if (empty(Configuration::read()['tld'])) {
        Configuration::writeBaseConfiguration();
    }

    /**
     * Get or set the TLD currently being used by Valet.
     */
    $app->command('tld [tld]', function ($tld = null) {
        if ($tld === null) {
            return info(Configuration::read()['tld']);
        }

        DnsMasq::updateTld(
            $oldTld = Configuration::read()['tld'], $tld = trim($tld, '.')
        );

        Configuration::updateKey('tld', $tld);

        Site::resecureForNewTld($oldTld, $tld);
        PhpFpm::restart();
        Nginx::restart();

        info('Your Valet TLD has been updated to ['.$tld.'].');
    }, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Get all the current sites within paths parked with 'park {path}'
     */
    $app->command('parked', function () {
        $parked = Site::parked();

        table(['Site', 'SSL', 'URL', 'Path'], $parked->all());
    })->descriptions('Display all the current sites within parked paths');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function ($path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? "This" : "The [{$path}]") . " directory has been removed from Valet's paths.");
    })->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name] [--secure]', function ($name, $secure) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A ['.$name.'] symbolic link has been created in ['.$linkPath.'].');

        if ($secure) {
            $this->runCommand('secure '.$name);
        }
    })->descriptions('Link the current working directory to Valet');

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function () {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function ($name) {
        info('The ['.Site::unlink($name).'] symbolic link has been removed.');
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain]', function ($domain = null) {
        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

        Site::secure($url);

        Nginx::restart();

        info('The ['.$url.'] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate');

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain] [--all]', function ($domain = null, $all) {

        if ($all) {
            Site::unsecureAll();
            return;
        }

        $url = ($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];

        Site::unsecure($url);

        Nginx::restart();

        info('The ['.$url.'] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Determine which Valet driver the current directory is using.
     */
    $app->command('which', function () {
        require __DIR__.'/drivers/require.php';

        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by ['.get_class($driver).'].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Determine which Valet driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            output(json_encode($paths, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            info('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current or given directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = "http://".($domain ?: Site::host(getcwd())).'.'.Configuration::read()['tld'];
        CommandLine::runAsUser("open ".escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        warning("It looks like you are running `cli/valet.php` directly, please use the `valet` script in the project root instead.");
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url [domain]', function ($domain = null) {
        output(Ngrok::currentTunnelUrl($domain ?: Site::host(getcwd()).'.'.Configuration::read()['tld']));
    })->descriptions('Get the URL to the current Ngrok tunnel');

    /**
     * Start the daemon services.
     */
    $app->command('start', function () {
        DnsMasq::restart();

        PhpFpm::restart();

        Nginx::restart();

        info('Valet services have been started.');
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart', function () {
        DnsMasq::restart();

        PhpFpm::restart();

        Nginx::restart();

        info('Valet services have been restarted.');
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop', function () {
        PhpFpm::stopRunning();

        Nginx::stop();

        info('Valet services have been stopped.');
    })->descriptions('Stop the Valet services');

    /**
     * Uninstall Valet entirely.
     */
    $app->command('uninstall', function () {
        output("WAIT! Before you uninstall things, consider cleaning things up in the following order. (Or skip to the bottom for troubleshooting suggestions.):
<info>The uninstall of Valet is not automated</info>, since a forced removal may delete your custom configuration information.

<info>1. Valet Keychain Certificates</info>
Before removing Valet configuration files, we recommend that you run <comment>valet unsecure --all</comment> to clean up the certificates that Valet inserted into your Keychain.
Alternatively you can do a search for <comment>@laravel.valet</comment> in KeychainAccess and delete those certificates there manually.
You may also run <comment>valet parked</comment> to see a list of all sites Valet could serve.

<info>2. Homebrew Services</info>
<fg=red>You may remove the core services (php, nginx, dnsmasq) by running:</> <comment>brew uninstall --force php nginx dnsmasq</comment>
<fg=red>You can then remove selected leftover configurations for these services manually</> in your <comment>/usr/local/etc/</comment> subdirectory.
<error>BEWARE:</error> Uninstalling PHP via Homebrew will leave your Mac with its original PHP version, which may not be compatible with other composer dependencies you have installed. Thus you may get unexpected errors.

<info>3. Valet Configuration Files</info>
<fg=red>You may remove your user-specific Valet config files by running:</>  <comment>rm -rf ~/.config/valet</comment>

<info>4. Other Housekeeping</info>
Some additional services which you may have installed (but which Valet does not directly configure or manage) include: <comment>mariadb mysql mailhog</comment>.
If you wish to also remove them, you may manually run <comment>brew uninstall SERVICENAME</comment> and clean up their configurations in /usr/local/etc if necessary.

You can discover more homebrew services by running: <comment>brew services list</comment>

<info>5. Remove Valet package</info>
You can run <comment>composer global remove laravel/valet</comment> to uninstall the Valet package.

<info>6. GENERAL TROUBLESHOOTING</info>
If your reasons for considering an uninstall are more for troubleshooting purposes, consider running <comment>brew doctor</comment> and/or <comment>brew cleanup</comment> to see if any problems exist there.
Also consider running <comment>sudo nginx -t</comment> to test your nginx configs in case there are failures/errors there preventing nginx from running.
Most of the nginx configs used by Valet are in your ~/.config/valet/Nginx directory.

You might also want to investigate your global composer configs. Helpful commands there include:
<comment>composer global diagnose</comment> and <comment>composer global outdated</comment>
as well as <comment>composer global update</comment>
");

        // Stopping PHP so the ~/.config/valet/valet.sock file is released so the directory can be deleted if desired
        PhpFpm::stopRunning();

        Nginx::uninstall(); // this currently only calls stop()

    })->descriptions('Uninstall the Valet services');

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('on-latest-version', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('Yes');
        } else {
            output(sprintf('Your version of Valet (%s) is not the latest version available.', $version));
            output('Upgrade instructions can be found in the docs: https://laravel.com/docs/valet#upgrading');
        }
    })->descriptions('Determine if this is the latest version of Valet');

    /**
     * Install the sudoers.d entries so password is no longer required.
     */
    $app->command('trust', function () {
        Brew::createSudoersEntry();
        Valet::createSudoersEntry();

        info('Sudoers entries have been added for Brew and Valet.');
    })->descriptions('Add sudoers files for Brew and Valet to make Valet commands run without passwords');

    /**
     * Allow the user to change the version of php valet uses
     */
    $app->command('use phpVersion', function ($phpVersion) {
        PhpFpm::stopRunning();

        $newVersion = PhpFpm::useVersion($phpVersion);

        Nginx::restart();
        info(sprintf('Valet is now using %s.', $newVersion));
        info('Note that you might need to run <comment>composer global update</comment> if your PHP version change affects the dependencies of global packages required by Composer.');
    })->descriptions('Change the version of PHP used by valet', [
        'phpVersion' => 'The PHP version you want to use, e.g php@7.2',
    ]);

    /**
     * Tail log file.
     */
    $app->command('log [-f|--follow] [-l|--lines=] [key]', function ($follow, $lines, $key = null) {
        $defaultLogs = [
            'php-fpm' => '/usr/local/var/log/php-fpm.log',
            'nginx' => VALET_HOME_PATH.'/Log/nginx-error.log',
            'mailhog' => '/usr/local/var/log/mailhog.log',
            'redis' => '/usr/local/var/log/redis.log',
        ];

        $configLogs = data_get(Configuration::read(), 'logs');
        if (! is_array($configLogs)) {
            $configLogs = [];
        }

        $logs = array_merge($defaultLogs, $configLogs);
        ksort($logs);

        if (! $key) {
            info(implode(PHP_EOL, [
                'In order to tail a log, pass the relevant log key (e.g. "nginx")',
                'along with any optional tail parameters (e.g. "-f" for follow).',
                null,
                'For example: "valet log nginx -f --lines=3"',
                null,
                'Here are the logs you might be interested in.',
                null,
            ]));

            table(
                ['Keys', 'Files'],
                collect($logs)->map(function ($file, $key) {
                    return [$key, $file];
                })->toArray()
            );

            info(implode(PHP_EOL, [
                null,
                'Tip: Set custom logs by adding a "logs" key/file object',
                'to your "'.Configuration::path().'" file.',
            ]));

            exit;
        }

        if (! isset($logs[$key])) {
            return warning('No logs found for ['.$key.'].');
        }

        $file = $logs[$key];
        if (! file_exists($file)) {
            return warning('Log path ['.$file.'] does not (yet) exist.');
        }

        $options = [];
        if ($follow) {
            $options[] = '-f';
        }
        if ((int) $lines) {
            $options[] = '-n '.(int) $lines;
        }

        $command = implode(' ', array_merge(['tail'], $options, [$file]));

        passthru($command);
    })->descriptions('Tail log file');
}

/**
 * Load all of the Valet extensions.
 */
foreach (Valet::extensions() as $extension) {
    include $extension;
}

/**
 * Run the application.
 */
$app->run();
