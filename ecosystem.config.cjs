// PM2 process config for Amanahku (Laravel dev server)
// Keeps `php artisan serve` alive across sessions, sleep, and crashes.
module.exports = {
  apps: [
    {
      name: 'amanahku-8888',
      script: 'artisan',
      args: 'serve --host=127.0.0.1 --port=8888',
      // Laragon PHP. If PHP is upgraded, update this path (or set to 'php' if on PATH).
      interpreter: 'C:/laragon/bin/php/php-8.3.30-Win32-vs16-x64/php.exe',
      cwd: 'C:/Users/User/Desktop/Claude/ClaudeCode/Aril/ProjectAI/SpecialProject/AmanahKu',
      autorestart: true,
      max_restarts: 10,
      env: {
        APP_ENV: 'local',
      },
    },
  ],
};
