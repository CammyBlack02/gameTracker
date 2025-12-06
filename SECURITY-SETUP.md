# Security Setup Guide for gameTracker

Complete guide for securing gameTracker for public access.

## 1. SSL/HTTPS Setup

### Install Certbot

```bash
sudo apt update
sudo apt install certbot python3-certbot-nginx -y
```

### Obtain SSL Certificate

**Option A: Using Domain Name (Recommended)**
```bash
sudo certbot --nginx -d yourdomain.com
```

**Option B: Using IP Address (Requires DNS Challenge)**
```bash
# For IP-based certificates, you'll need to use DNS challenge
# This is more complex - consider getting a free domain name instead
```

### Update Nginx Configuration

After running certbot, it will automatically update your Nginx config. However, you need to:

1. Update `nginx-gameTracker.conf`:
   - Replace `YOUR_DOMAIN_OR_IP` with your actual domain or IP
   - Update SSL certificate paths if needed

2. Test Nginx configuration:
```bash
sudo nginx -t
```

3. Reload Nginx:
```bash
sudo systemctl reload nginx
```

### Auto-Renewal

Certbot sets up automatic renewal. Test it:
```bash
sudo certbot renew --dry-run
```

## 2. Firewall Configuration (UFW)

### Enable UFW

```bash
sudo ufw enable
```

### Allow Required Ports

```bash
# SSH (keep your current session open!)
sudo ufw allow 22/tcp

# HTTP
sudo ufw allow 80/tcp

# HTTPS
sudo ufw allow 443/tcp
```

### Verify Rules

```bash
sudo ufw status verbose
```

### Block Everything Else

UFW defaults to deny all incoming, which is good. Verify:
```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
```

## 3. PHP Security Configuration

### Apply Security Settings

Copy security settings to PHP-FPM pool config:

```bash
sudo nano /etc/php/8.3/fpm/pool.d/www.conf
```

Add or modify these settings in the `[www]` section:
```ini
php_admin_value[expose_php] = Off
php_admin_value[disable_functions] = exec,passthru,shell_exec,system,proc_open,popen
php_admin_value[upload_max_filesize] = 10M
php_admin_value[post_max_size] = 12M
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_secure] = 1
php_admin_value[session.use_strict_mode] = 1
```

### Restart PHP-FPM

```bash
sudo systemctl restart php8.3-fpm
```

## 4. MySQL Security

### Secure MySQL Installation

```bash
sudo mysql_secure_installation
```

Follow prompts to:
- Set root password (if not already set)
- Remove anonymous users
- Disable remote root login
- Remove test database

### Verify MySQL Binding

Ensure MySQL only listens on localhost:
```bash
sudo nano /etc/mysql/mysql.conf.d/mysqld.cnf
```

Verify:
```ini
bind-address = 127.0.0.1
```

Restart MySQL:
```bash
sudo systemctl restart mysql
```

## 5. File Permissions

### Set Correct Ownership

```bash
sudo chown -R www-data:www-data /var/www/gameTracker
```

### Set Secure Permissions

```bash
# Directories
find /var/www/gameTracker -type d -exec chmod 755 {} \;

# Files
find /var/www/gameTracker -type f -exec chmod 644 {} \;

# Uploads directory (writable)
chmod 755 /var/www/gameTracker/uploads
chmod 755 /var/www/gameTracker/uploads/covers
chmod 755 /var/www/gameTracker/uploads/extras
```

### Protect Sensitive Files

```bash
# Protect config files
chmod 600 /var/www/gameTracker/includes/config.php

# Protect .htaccess if you add one
chmod 644 /var/www/gameTracker/.htaccess
```

## 6. Nginx Configuration

### Deploy Updated Config

1. Copy config to Nginx sites-available:
```bash
sudo cp nginx-gameTracker.conf /etc/nginx/sites-available/gameTracker
```

2. Update SSL certificate paths in the config file:
```bash
sudo nano /etc/nginx/sites-available/gameTracker
```

Replace `YOUR_DOMAIN_OR_IP` with your actual domain or IP address.

3. Enable site:
```bash
sudo ln -s /etc/nginx/sites-available/gameTracker /etc/nginx/sites-enabled/
```

4. Remove default site (optional):
```bash
sudo rm /etc/nginx/sites-enabled/default
```

5. Test and reload:
```bash
sudo nginx -t
sudo systemctl reload nginx
```

## 7. Security Testing Checklist

- [ ] HTTPS works and HTTP redirects to HTTPS
- [ ] Security headers are present (check with browser dev tools)
- [ ] Rate limiting works (try multiple login attempts)
- [ ] robots.txt is accessible and blocks crawlers
- [ ] Meta tags prevent indexing (view page source)
- [ ] File uploads are validated (try uploading non-image file)
- [ ] Session timeout works (wait 30 minutes, try to use site)
- [ ] Failed login attempts are logged
- [ ] UFW firewall only allows ports 22, 80, 443
- [ ] MySQL is not accessible from external IPs
- [ ] PHP dangerous functions are disabled

## 8. Monitoring

### Check Security Logs

```bash
# Nginx access logs
sudo tail -f /var/log/nginx/gameTracker-access.log

# Nginx error logs
sudo tail -f /var/log/nginx/gameTracker-error.log

# PHP error logs
sudo tail -f /var/log/php8.3-fpm.log

# Security events (in database)
mysql -u CammyBlack02 -p gameTracker -e "SELECT * FROM security_logs ORDER BY created_at DESC LIMIT 20;"
```

### Monitor Failed Login Attempts

```bash
mysql -u CammyBlack02 -p gameTracker -e "SELECT * FROM security_logs WHERE event_type LIKE '%login%' ORDER BY created_at DESC LIMIT 50;"
```

### Check Rate Limits

```bash
mysql -u CammyBlack02 -p gameTracker -e "SELECT * FROM rate_limits WHERE locked_until > NOW() ORDER BY locked_until DESC;"
```

## 9. Regular Maintenance

### Update System

```bash
sudo apt update
sudo apt upgrade -y
```

### Renew SSL Certificates

Certbot handles this automatically, but verify:
```bash
sudo certbot renew --dry-run
```

### Review Security Logs Weekly

Check for suspicious activity:
- Multiple failed login attempts from same IP
- Unusual file uploads
- Rate limit violations

### Backup Database Regularly

```bash
mysqldump -u CammyBlack02 -p gameTracker > backup_$(date +%Y%m%d).sql
```

## 10. Additional Security Recommendations

1. **Change Default Admin Password**: Immediately after first login
2. **Use Strong Passwords**: Enforce strong password policy
3. **Regular Backups**: Automate database backups
4. **Monitor Logs**: Set up log monitoring/alerts
5. **Keep Updated**: Regularly update server OS and software
6. **Limit Admin Access**: Only give admin role to trusted users
7. **Use VPN** (Optional): Require VPN access before accessing site

## Troubleshooting

**SSL Certificate Issues:**
- Ensure port 80 is accessible for verification
- Check DNS settings if using domain
- Verify certificate paths in Nginx config

**Rate Limiting Too Strict:**
- Adjust limits in `nginx-gameTracker.conf`
- Modify rate limit zones as needed

**Session Timeout Issues:**
- Check session configuration in `includes/config.php`
- Verify PHP session settings

**File Upload Issues:**
- Check file permissions on uploads directory
- Verify PHP upload_max_filesize and post_max_size
- Check Nginx client_max_body_size

