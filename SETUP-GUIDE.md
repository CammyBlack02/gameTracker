# gameTracker - Complete Setup Guide

A comprehensive guide to setting up gameTracker from scratch, including server configuration, network setup, and security hardening.

## Table of Contents

1. [Overview](#overview)
2. [Architecture](#architecture)
3. [Prerequisites](#prerequisites)
4. [Server Setup](#server-setup)
5. [Database Configuration](#database-configuration)
6. [Web Server Configuration](#web-server-configuration)
7. [Network Configuration](#network-configuration)
8. [Security Hardening](#security-hardening)
9. [Application Features](#application-features)
10. [Maintenance](#maintenance)
11. [Troubleshooting](#troubleshooting)

---

## Overview

gameTracker is a web-based application for managing personal game collections. It supports:

- Multi-user accounts with separate collections
- Game metadata (title, platform, genre, condition, etc.)
- Cover image management (local storage + external URLs)
- Completion tracking
- Console/accessory inventory
- Statistics and analytics
- GameEye CSV import
- Spin wheel for random game selection

### Technology Stack

- **Backend**: PHP 8.3 with MySQL
- **Web Server**: Nginx
- **Database**: MySQL 8.0+
- **Frontend**: Vanilla JavaScript, CSS3
- **Security**: HTTPS (Let's Encrypt), Fail2ban, UFW firewall

---

## Architecture

### System Components

```
┌─────────────────┐
│   Internet      │
└────────┬────────┘
         │
    ┌────▼────┐
    │  Router │ (UniFi)
    │  (Port  │
    │  80/443)│
    └────┬────┘
         │
    ┌────▼────────────┐
    │  Ubuntu Server  │
    │  ┌───────────┐ │
    │  │  Nginx    │ │
    │  │  (HTTPS)  │ │
    │  └─────┬─────┘ │
    │        │       │
    │  ┌─────▼─────┐ │
    │  │ PHP-FPM   │ │
    │  └─────┬─────┘ │
    │        │       │
    │  ┌─────▼─────┐ │
    │  │  MySQL    │ │
    │  └───────────┘ │
    └────────────────┘
```

### Network Zones

- **Secured Zone**: Server network (isolated VLAN)
- **Guest Network**: Separate network for visitors
- **Firewall Rules**: Only ports 80/443 forwarded to server

---

## Prerequisites

### Hardware Requirements

- Ubuntu Server 22.04+ (or similar Linux distribution)
- Minimum 2GB RAM
- 10GB+ free disk space
- Static IP or Dynamic DNS (DuckDNS recommended)

### Software Requirements

- PHP 8.3+ with extensions: `php-fpm`, `php-mysql`, `php-curl`, `php-gd`
- MySQL 8.0+
- Nginx
- Certbot (for SSL certificates)
- Git

---

## Server Setup

### 1. Initial Server Configuration

```bash
# Update system
sudo apt update && sudo apt upgrade -y

# Install required packages
sudo apt install -y nginx mysql-server php8.3-fpm php8.3-mysql php8.3-curl php8.3-gd php8.3-mbstring git

# Install Certbot for SSL
sudo apt install -y certbot python3-certbot-nginx
```

### 2. Clone Repository

```bash
# Navigate to web root
cd /var/www

# Clone repository (replace with your repo URL)
sudo git clone https://github.com/yourusername/gameTracker.git
sudo chown -R www-data:www-data gameTracker
```

### 3. Configure File Permissions

```bash
cd /var/www/gameTracker

# Set directory permissions
sudo find . -type d -exec chmod 755 {} \;

# Set file permissions
sudo find . -type f -exec chmod 644 {} \;

# Secure config file
sudo chmod 600 includes/config.php

# Make uploads writable
sudo chmod 755 uploads uploads/covers uploads/extras
```

---

## Database Configuration

### 1. Create Database and User

```bash
# Access MySQL
sudo mysql

# In MySQL prompt:
CREATE DATABASE gameTracker CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'your_username'@'localhost' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON gameTracker.* TO 'your_username'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### 2. Configure Database Connection

Edit `includes/config.php`:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'gameTracker');
define('DB_USER', 'your_username');
define('DB_PASS', 'your_secure_password');
```

### 3. Initialize Database

The database tables are automatically created on first access. The application will:

- Create all necessary tables
- Set up foreign key relationships
- Create default admin user (change credentials immediately!)

---

## Web Server Configuration

### 1. Nginx Configuration

Copy `nginx-gameTracker.conf` to Nginx sites:

```bash
sudo cp nginx-gameTracker.conf /etc/nginx/sites-available/gameTracker
sudo ln -s /etc/nginx/sites-available/gameTracker /etc/nginx/sites-enabled/
sudo rm /etc/nginx/sites-enabled/default  # Remove default site
```

### 2. Update Configuration

Edit `/etc/nginx/sites-available/gameTracker`:

- Update `server_name` with your domain
- Verify PHP-FPM socket path (usually `/var/run/php/php8.3-fpm.sock`)
- Update `root` path if different

### 3. Test and Reload

```bash
sudo nginx -t
sudo systemctl reload nginx
```

### 4. SSL Certificate Setup

```bash
# Obtain SSL certificate
sudo certbot --nginx -d yourdomain.com

# Test auto-renewal
sudo certbot renew --dry-run
```

Certbot will automatically:
- Configure SSL in Nginx
- Set up auto-renewal
- Redirect HTTP to HTTPS

---

## Network Configuration

### UniFi Network Setup

#### 1. Create Network Zones

- **Secured Zone**: Server network (VLAN with firewall rules)
- **Guest Network**: Separate network (optional)

#### 2. Configure Firewall Rules

**Port Forwarding**:
- Port 80 (HTTP) → Server IP
- Port 443 (HTTPS) → Server IP

**Firewall Rules**:
- Allow HTTP/HTTPS from Internet to Server
- Block all other incoming traffic
- Allow server to access Internet (for updates, API calls)

#### 3. Dynamic DNS (DuckDNS)

If using dynamic IP:

1. Sign up at https://www.duckdns.org
2. Create a subdomain (e.g., `yourname.duckdns.org`)
3. Update DNS in UniFi or use DuckDNS updater script
4. Update Nginx `server_name` with your DuckDNS domain

---

## Security Hardening

### 1. Firewall (UFW)

```bash
# Enable firewall
sudo ufw enable

# Allow required ports
sudo ufw allow 22/tcp   # SSH
sudo ufw allow 80/tcp   # HTTP
sudo ufw allow 443/tcp  # HTTPS

# Verify
sudo ufw status verbose
```

### 2. PHP Security

Edit `/etc/php/8.3/fpm/pool.d/www.conf`:

```ini
php_admin_value[expose_php] = Off
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen,parse_ini_file,show_source
```

Restart PHP:
```bash
sudo systemctl restart php8.3-fpm
```

### 3. MySQL Security

Ensure MySQL only listens on localhost:

```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Verify:
```ini
bind-address = 127.0.0.1
```

### 4. Fail2ban (Brute Force Protection)

```bash
# Install
sudo apt install fail2ban -y

# Configure (optional - defaults work well)
sudo cp /etc/fail2ban/jail.conf /etc/fail2ban/jail.local
sudo nano /etc/fail2ban/jail.local

# Enable and start
sudo systemctl enable fail2ban
sudo systemctl start fail2ban
```

### 5. Automatic Security Updates

```bash
# Install
sudo apt install unattended-upgrades -y

# Configure
sudo dpkg-reconfigure -plow unattended-upgrades
```

Enable:
- Automatic security updates
- Remove unused dependencies
- Auto-reboot: No (or Yes if preferred)

### 6. Security Headers

Security headers are configured in Nginx. Verify they're active:

```bash
curl -I https://yourdomain.com | grep -i "x-frame\|strict-transport\|content-security"
```

### 7. Search Engine Blocking

- `robots.txt` blocks all crawlers
- Meta tags on all pages: `<meta name="robots" content="noindex, nofollow">`
- Nginx header: `X-Robots-Tag: noindex, nofollow`

---

## Application Features

### User Management

- **Multi-user support**: Each user has their own collection
- **Admin accounts**: Special privileges (user management, etc.)
- **User profiles**: Browse other users' collections
- **Registration**: Open registration (can be restricted)

### Game Management

- **CRUD operations**: Create, read, update, delete games
- **Metadata**: Title, platform, genre, condition, release date, etc.
- **Cover images**: Local storage + external URL support
- **Bulk import**: GameEye CSV import with smart merging
- **Filtering**: By platform, genre, condition, completion status
- **Views**: Grid, list, and coverflow views

### Additional Features

- **Spin wheel**: Random game selection with filters
- **Completions**: Track game completion dates and times
- **Statistics**: Collection analytics and charts
- **Items**: Console and accessory inventory
- **Image proxy**: Bypass CORS for external images

---

## Maintenance

### Automated Backups

A backup script is included (`backup-gameTracker.sh`). Set up cron:

```bash
# Edit crontab
crontab -e

# Add daily backup at 2 AM
0 2 * * * /path/to/backup-gameTracker.sh
```

Backups include:
- Database dump (compressed)
- Uploaded files
- Configuration files

### Security Monitoring

Security log monitoring script (`check-security-logs.sh`):

```bash
# Add to crontab (runs hourly)
0 * * * * /path/to/check-security-logs.sh
```

Monitors:
- Failed login attempts
- Suspicious scanning (404 errors)
- SQL injection attempts
- Sensitive file access attempts
- Fail2ban bans

### Log Files

Important log locations:

- Nginx access: `/var/log/nginx/gameTracker-access.log`
- Nginx errors: `/var/log/nginx/gameTracker-error.log`
- PHP errors: `/var/log/php8.3-fpm.log`
- Security events: `~/logs/security-alerts.log`

### Updating the Application

```bash
cd /var/www/gameTracker
sudo git pull origin main
sudo systemctl reload nginx
sudo systemctl restart php8.3-fpm
```

---

## Troubleshooting

### Common Issues

#### 1. 500 Internal Server Error

```bash
# Check PHP error log
sudo tail -50 /var/log/php8.3-fpm.log

# Check Nginx error log
sudo tail -50 /var/log/nginx/gameTracker-error.log

# Verify file permissions
ls -la /var/www/gameTracker/includes/config.php
```

#### 2. Database Connection Errors

```bash
# Test MySQL connection
mysql -u your_username -p gameTracker

# Check MySQL is running
sudo systemctl status mysql

# Verify credentials in config.php
```

#### 3. Image Upload Failures

```bash
# Check upload directory permissions
ls -ld /var/www/gameTracker/uploads

# Check PHP upload limits
php -i | grep upload_max_filesize

# Check Nginx client_max_body_size
grep client_max_body_size /etc/nginx/sites-available/gameTracker
```

#### 4. SSL Certificate Issues

```bash
# Check certificate status
sudo certbot certificates

# Test renewal
sudo certbot renew --dry-run

# Manually renew if needed
sudo certbot renew
```

#### 5. Port Not Accessible

- Verify firewall rules in UniFi
- Check UFW status: `sudo ufw status`
- Verify port forwarding in router
- Test from external network: `curl https://yourdomain.com`

---

## Security Best Practices

### Initial Setup

1. ✅ Change default admin credentials immediately
2. ✅ Use strong database passwords
3. ✅ Keep system updated
4. ✅ Monitor security logs regularly
5. ✅ Review backup integrity periodically

### Ongoing Maintenance

1. ✅ Review security logs weekly
2. ✅ Update application code regularly
3. ✅ Monitor fail2ban bans
4. ✅ Verify backups are working
5. ✅ Keep SSL certificate renewed (automatic)

### Access Control

- Limit admin accounts to trusted users only
- Use strong, unique passwords
- Consider 2FA for admin accounts (future enhancement)
- Regularly review user accounts

---

## File Structure

```
gameTracker/
├── api/                    # API endpoints
│   ├── auth.php           # Authentication
│   ├── games.php          # Game CRUD
│   ├── upload.php         # File uploads
│   └── ...
├── includes/              # PHP includes
│   ├── config.php         # Database config
│   ├── auth-check.php     # Auth validation
│   └── functions.php      # Helper functions
├── css/                   # Stylesheets
├── js/                    # JavaScript
├── uploads/               # User uploads
│   ├── covers/           # Game covers
│   └── extras/           # Extra images
├── dashboard.php          # Main dashboard
├── index.php             # Login page
├── nginx-gameTracker.conf # Nginx config
└── robots.txt            # Search engine blocking
```

---

## API Endpoints

### Authentication
- `POST /api/auth.php?action=login` - User login
- `POST /api/auth.php?action=register` - User registration
- `POST /api/auth.php?action=logout` - User logout

### Games
- `GET /api/games.php?action=list` - List games (paginated)
- `GET /api/games.php?action=get&id=X` - Get game details
- `POST /api/games.php?action=create` - Create game
- `POST /api/games.php?action=update` - Update game
- `POST /api/games.php?action=delete` - Delete game

### Uploads
- `POST /api/upload.php` - Upload image (cover or extra)

All endpoints require authentication except registration.

---

## Development Notes

### Database Schema

Key tables:
- `users` - User accounts and roles
- `games` - Game collection data
- `items` - Console/accessory inventory
- `game_completions` - Completion tracking
- `game_images` - Extra game images
- `security_logs` - Security event logging
- `rate_limits` - Rate limiting data

### Security Features

- **Prepared statements**: All database queries
- **CSRF protection**: Tokens for sensitive operations
- **XSS protection**: HTML escaping throughout
- **File upload validation**: MIME type, size, dimensions
- **Rate limiting**: Application and Nginx levels
- **Session security**: Secure cookies, timeout, regeneration

---

## Credits

This application was developed with assistance from AI coding assistants (Claude/Anthropic via Cursor IDE) for implementation, security hardening, and documentation.

---

## License

[Add your license here]

---

## Support

For issues or questions:
1. Check the troubleshooting section
2. Review log files
3. Check GitHub issues (if public repo)
4. Review security assessment: `SECURITY-ASSESSMENT.md

---

**Last Updated**: December 2025

