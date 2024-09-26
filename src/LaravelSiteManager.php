<?php

namespace Wovosoft\LaravelSiteManager;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\select;

class LaravelSiteManager
{
    protected string $folderName;
    protected string $domain;
    protected string $sitesAvailable;
    protected string $sitesEnabled;
    protected string $hostsFile = '/etc/hosts';

    public function __construct()
    {
        $this->folderName = basename(getcwd());
        $this->domain = $this->folderName . '.test';
        $this->sitesAvailable = "/etc/nginx/sites-available/{$this->domain}";
        $this->sitesEnabled = "/etc/nginx/sites-enabled/{$this->domain}";
    }

    // Handle site creation
    public function createSite(): void
    {
        $this->createNginxConfig();
        $this->createSymlink();
        $this->updateHostsFile();
        $this->restartNginx();
    }

    // Handle site deletion
    public function deleteSite(): void
    {
        if ($this->confirmDeletion()) {
            $this->deleteNginxConfig();
            $this->deleteSymlink();
            $this->removeFromHostsFile();
            $this->restartNginx();
        } else {
            echo "Deletion canceled.\n";
        }
    }

    // Create Nginx configuration file
    protected function createNginxConfig(): void
    {
        $nginxConfig = <<<EOL
server {
    listen 80;
    server_name {$this->domain};
    root /var/www/{$this->folderName}/public;

    index index.php index.html index.htm;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.ht {
        deny all;
    }
}
EOL;

        if (file_put_contents($this->sitesAvailable, $nginxConfig) === false) {
            echo "Failed to create Nginx config file in sites-available\n";
            exit(1);
        }

        echo "Nginx config created: {$this->sitesAvailable}\n";
    }

    // Create a symlink in sites-enabled
    protected function createSymlink(): void
    {
        if (!is_link($this->sitesEnabled)) {
            if (!symlink($this->sitesAvailable, $this->sitesEnabled)) {
                echo "Failed to create symlink in sites-enabled\n";
                exit(1);
            }
            echo "Symlink created: {$this->sitesEnabled}\n";
        } else {
            echo "Symlink already exists: {$this->sitesEnabled}\n";
        }
    }

    // Update the /etc/hosts file
    protected function updateHostsFile(): void
    {
        $hostsEntry = "127.0.0.1    {$this->domain}";
        $hostsContents = file_get_contents($this->hostsFile);

        if (str_contains($hostsContents, $this->domain)) {
            // Update the domain if already present
            $newHostsContents = preg_replace("/^.*{$this->domain}.*\$/m", $hostsEntry, $hostsContents);
            if (file_put_contents($this->hostsFile, $newHostsContents) === false) {
                echo "Failed to update entry in /etc/hosts\n";
                exit(1);
            }
            echo "Hosts file entry updated: {$this->domain}\n";
        } else {
            // Add new domain entry
            if (file_put_contents($this->hostsFile, $hostsEntry . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                echo "Failed to add entry to /etc/hosts\n";
                exit(1);
            }
            echo "Entry added to /etc/hosts: {$this->domain}\n";
        }
    }

    // Restart Nginx
    protected function restartNginx(): void
    {
        exec('sudo systemctl restart nginx', $output, $returnCode);
        if ($returnCode !== 0) {
            echo "Failed to restart Nginx\n";
            exit(1);
        }
        echo "Nginx restarted successfully\n";
    }

    // Delete Nginx configuration
    protected function deleteNginxConfig(): void
    {
        if (file_exists($this->sitesAvailable)) {
            unlink($this->sitesAvailable);
            echo "Nginx config deleted: {$this->sitesAvailable}\n";
        } else {
            echo "Nginx config not found: {$this->sitesAvailable}\n";
        }
    }

    // Delete symlink in sites-enabled
    protected function deleteSymlink(): void
    {
        if (is_link($this->sitesEnabled)) {
            unlink($this->sitesEnabled);
            echo "Symlink deleted: {$this->sitesEnabled}\n";
        } else {
            echo "Symlink not found: {$this->sitesEnabled}\n";
        }
    }

    // Remove domain from hosts file
    protected function removeFromHostsFile(): void
    {
        $hostsContents = file_get_contents($this->hostsFile);

        if (str_contains($hostsContents, $this->domain)) {
            $newHostsContents = preg_replace("/^.*{$this->domain}.*\$/m", '', $hostsContents);
            if (file_put_contents($this->hostsFile, $newHostsContents) === false) {
                echo "Failed to update /etc/hosts\n";
                exit(1);
            }
            echo "Entry removed from /etc/hosts: {$this->domain}\n";
        } else {
            echo "Domain not found in /etc/hosts: {$this->domain}\n";
        }
    }

    // Confirm deletion from user
    protected function confirmDeletion(): bool
    {
        return confirm("Are you sure you want to delete the configuration for {$this->domain}?");
    }
}

// Initialize LaravelSiteManager
$siteManager = new LaravelSiteManager();

// Prompt the user for an action
$action = select(label: 'Select action:', options: [
    'create' => 'Create a new domain configuration',
    'delete' => 'Delete domain configuration'
]);

if ($action === 'create') {
    $siteManager->createSite();
} elseif ($action === 'delete') {
    $siteManager->deleteSite();
}
