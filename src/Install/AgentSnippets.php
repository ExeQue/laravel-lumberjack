<?php

declare(strict_types=1);

namespace ExeQue\Lumberjack\Install;

/**
 * How to run the agent under each process manager, as lumberjack:install prints it.
 * Kept in step with docs/agent.md.
 */
final class AgentSnippets
{
    public const array PLATFORMS = [
        'supervisor' => 'Forge or Supervisor',
        'systemd' => 'systemd',
        'docker' => 'Docker or Docker Compose',
        'kubernetes' => 'Kubernetes',
        'later' => 'I will set it up later',
    ];

    /**
     * @return array{string, string} where the snippet goes, and the snippet; empty for "later"
     */
    public static function for(string $platform, string $basePath): array
    {
        return match ($platform) {
            'supervisor' => ['A Supervisor program, e.g. /etc/supervisor/conf.d/lumberjack-agent.conf (on Forge: a daemon running "php artisan lumberjack:agent" in the site directory):', <<<INI
                [program:lumberjack-agent]
                command=php {$basePath}/artisan lumberjack:agent
                autostart=true
                autorestart=true
                stopwaitsecs=15
                user=www-data
                INI],
            'systemd' => ['A systemd unit, e.g. /etc/systemd/system/lumberjack-agent.service, then "systemctl enable --now lumberjack-agent":', <<<INI
                [Unit]
                Description=Lumberjack agent
                After=network.target

                [Service]
                ExecStart=/usr/bin/php {$basePath}/artisan lumberjack:agent
                Restart=always
                RestartSec=5
                User=www-data

                [Install]
                WantedBy=multi-user.target
                INI],
            'docker' => ['A service next to the app in compose.yaml, from the same image. The agent listens on all addresses of its container, and the app sends to it by service name:', <<<'YAML'
                services:
                  app:
                    environment:
                      LUMBERJACK_AGENT_HOST: lumberjack-agent
                  lumberjack-agent:
                    image: your-app-image   # the app's own image
                    command: php artisan lumberjack:agent --host=0.0.0.0
                    restart: unless-stopped
                YAML],
            'kubernetes' => ['A sidecar container in the app\'s pod. It shares the pod\'s network, so the app reaches it on 127.0.0.1:', <<<'YAML'
                containers:
                  - name: lumberjack-agent
                    image: your-app-image   # the app's own image
                    command: ["php", "artisan", "lumberjack:agent"]
                    envFrom:
                      - secretRef:
                          name: your-app-env   # the same environment as the app
                YAML],
            default => ['', ''],
        };
    }
}
