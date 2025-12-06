# Security Checklist - Before Pushing to GitHub

## ✅ Immediate Actions Required

### 1. Verify config.php is NOT being committed
```bash
# This should return NOTHING (empty):
git status --short | grep "config.php"

# This should show config.php is ignored:
git check-ignore includes/config.php
```

### 2. Change Database Password (CRITICAL)
Your database credentials were previously committed to git history. **You MUST change your database password** before pushing.

**Option A: Use the change-admin-credentials script (recommended)**
```bash
# On your server, run:
php change-admin-credentials.php
```

**Option B: Change MySQL password directly**
```bash
# SSH into your server, then:
mysql -u root -p
# Enter MySQL root password when prompted

# Then in MySQL:
ALTER USER 'CammyBlack02'@'localhost' IDENTIFIED BY 'NEW_STRONG_PASSWORD_HERE';
FLUSH PRIVILEGES;
EXIT;
```

**Then update config.php on server:**
```bash
nano /var/www/gameTracker/includes/config.php
# Update DB_PASS to the new password
```

### 3. Verify What's Being Committed
```bash
# Check staged files (should NOT include config.php):
git diff --cached --name-only | grep config.php
# Should return nothing

# Verify config.php.example IS being added:
git status --short | grep config.php.example
# Should show: ?? includes/config.php.example
```

### 4. Stage the Correct Files
```bash
# Add the template (safe):
git add includes/config.php.example

# Add updated .gitignore (protects config.php):
git add .gitignore

# Verify config.php is NOT staged:
git diff --cached --name-only | grep -E "^includes/config\.php$"
# Should return nothing (config.php should NOT be here)
```

## ✅ Pre-Push Verification

Before running `git push`, verify:

1. ✅ `config.php` is in `.gitignore`
2. ✅ `config.php` is NOT in staged files
3. ✅ `config.php.example` IS staged (template file)
4. ✅ Database password has been changed
5. ✅ New password is updated in server's `config.php`

## 🔒 After Pushing

1. **Rotate any exposed credentials** (already done if you changed DB password)
2. **Monitor for unauthorized access** (check MySQL logs)
3. **Consider using git filter-branch** to remove config.php from history (optional, advanced)

## 📝 Quick Commands

```bash
# Verify config.php is ignored:
git check-ignore includes/config.php && echo "✅ Protected" || echo "❌ NOT PROTECTED!"

# See what would be committed:
git diff --cached --name-only

# If config.php appears, unstage it:
git restore --staged includes/config.php
```

