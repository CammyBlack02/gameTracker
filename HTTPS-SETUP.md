# HTTPS Setup for iPhone Access

iOS devices often require HTTPS connections. Here are several solutions:

## Option 1: Use ngrok (Easiest - Recommended)

ngrok creates a secure HTTPS tunnel to your local server.

### Setup:
1. Install ngrok:
   ```bash
   brew install ngrok/ngrok/ngrok
   ```

2. Sign up for free at https://dashboard.ngrok.com/signup

3. Get your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken

4. Configure ngrok:
   ```bash
   ngrok config add-authtoken YOUR_TOKEN_HERE
   ```

5. Start your PHP server:
   ```bash
   ./start-server.sh
   # Or: php -S localhost:8000
   ```

6. In another terminal, start ngrok:
   ```bash
   ngrok http 8000
   ```

7. Copy the HTTPS URL (e.g., `https://abc123.ngrok.io`) and use it on your iPhone

**Note:** Free ngrok URLs change each restart. Paid plans offer static URLs.

---

## Option 2: Self-Signed Certificate (Local Network Only)

This works for local network access but requires accepting a security warning.

### Setup:
1. Run the setup script:
   ```bash
   ./setup-https-server.sh
   ```

2. Start the HTTPS server:
   ```bash
   php -S 0.0.0.0:8443 -t . -c certs/server.pem
   ```

3. On your iPhone, go to: `https://192.168.0.59:8443`

4. Accept the security warning (it's safe - just a self-signed cert)

**Note:** You'll need to accept the certificate warning each time on iOS.

---

## Option 3: Use MAMP (Full Web Server)

MAMP provides a full Apache server with HTTPS support.

1. Download MAMP from https://www.mamp.info/

2. Install and start MAMP

3. Copy your gameTracker folder to `/Applications/MAMP/htdocs/`

4. Access via: `https://localhost:8888/gameTracker` (or your Mac IP)

5. Configure MAMP to use HTTPS port 8443

---

## Option 4: Use Cloudflare Tunnel (Free, Static URL)

Similar to ngrok but with a free static URL option.

1. Sign up at https://one.dash.cloudflare.com/

2. Install cloudflared:
   ```bash
   brew install cloudflare/cloudflare/cloudflared
   ```

3. Run tunnel:
   ```bash
   cloudflared tunnel --url http://localhost:8000
   ```

---

## Quick Test: Check if HTTP Works

Sometimes HTTP works fine on local networks. Try:

1. Make sure your iPhone and Mac are on the same Wi-Fi network

2. Start the server:
   ```bash
   ./start-server.sh
   ```

3. On iPhone Safari, try: `http://192.168.0.59:8000`

4. If it doesn't work, Safari might show a warning - try tapping "Advanced" and "Proceed"

---

## Recommended Solution

For easiest setup: **Use ngrok** - it's free, secure, and works immediately.

For permanent local access: **Use MAMP** - full web server with proper HTTPS support.

