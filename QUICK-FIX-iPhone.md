# Quick Fix for iPhone Connection Issues

The PHP built-in server sometimes has issues with iOS devices. Here are the best solutions:

## Solution 1: Use ngrok (Easiest & Most Reliable) ⭐

**This is the recommended solution!**

1. **Install ngrok:**
   ```bash
   brew install ngrok/ngrok/ngrok
   ```

2. **Sign up for free:** https://dashboard.ngrok.com/signup

3. **Get your authtoken** from: https://dashboard.ngrok.com/get-started/your-authtoken

4. **Configure ngrok:**
   ```bash
   ngrok config add-authtoken YOUR_TOKEN_HERE
   ```

5. **Start your PHP server** (in one terminal):
   ```bash
   php -S localhost:8000
   ```

6. **Start ngrok** (in another terminal):
   ```bash
   ngrok http 8000
   ```

7. **Copy the HTTPS URL** that ngrok shows (looks like `https://abc123.ngrok.io`)

8. **Use that URL on your iPhone** - it will work perfectly!

**Why this works:** ngrok creates a secure HTTPS tunnel, which iOS prefers, and handles all the connection issues.

---

## Solution 2: Use MAMP (Full Web Server)

MAMP provides a proper Apache server that works reliably with iOS.

1. **Download MAMP:** https://www.mamp.info/en/downloads/

2. **Install and start MAMP**

3. **Copy your gameTracker folder** to:
   ```
   /Applications/MAMP/htdocs/gameTracker
   ```

4. **Access via:**
   - Local: `http://localhost:8888/gameTracker`
   - iPhone: `http://192.168.0.59:8888/gameTracker`

5. **For HTTPS:** Configure MAMP to use port 8443 for HTTPS

---

## Solution 3: Try Different Browsers on iPhone

Sometimes Safari has issues, but other browsers work:

- **Chrome for iOS:** Try `http://192.168.0.59:8000`
- **Firefox for iOS:** Try `http://192.168.0.59:8000`

---

## Solution 4: Check Mac Firewall

Your Mac's firewall might be blocking connections:

1. **System Settings** → **Network** → **Firewall**
2. Make sure PHP is allowed, or temporarily disable firewall to test

---

## Why the PHP Built-in Server Has Issues

The PHP built-in development server (`php -S`) is designed for local development only. It sometimes has issues with:
- External network connections
- iOS security requirements
- HTTP/HTTPS handling
- Connection timeouts

**That's why ngrok or MAMP are better solutions for iPhone access.**

---

## Recommended: Use ngrok

It's free, takes 2 minutes to set up, and works perfectly with iOS. The free tier gives you a new URL each time (which is fine for testing).

