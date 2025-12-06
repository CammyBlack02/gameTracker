# UniFi Network Setup Guide for gameTracker

This guide will help you configure your UniFi network to securely host gameTracker for external access.

## Prerequisites

- UniFi Controller access
- Server already set up with Ubuntu Server
- Server's internal IP address (e.g., 192.168.0.100)

## Network Configuration

### 1. Server Network Zone

Your server should be in a **Secured** or **Internal** network zone.

**How to assign your server to the Secured zone:**

1. **Identify the server's network:**
   - In UniFi Controller, go to **Clients** or **Devices**
   - Find your server (by IP address or hostname)
   - Note which network/VLAN it's connected to

2. **Assign the network to Secured zone:**
   - Go to **Settings** → **Networks** (or **Networking** → **Networks**)
   - Click on the network your server is using
   - Scroll down to **Firewall & Security** section
   - Find **Firewall Zone** or **Network Group**
   - Select **Secured** from the dropdown
   - Click **Apply** or **Save**

3. **Alternative: Create a dedicated network for the server:**
   - If you want to isolate the server, create a new network:
     - Go to **Settings** → **Networks** → **Create New Network**
     - Name it something like "Server Network" or "gameTracker-Server"
     - Set it as a **VLAN** (e.g., VLAN ID 100)
     - Assign **Firewall Zone** as **Secured**
     - Configure IP range (e.g., 192.168.100.0/24)
     - Save the network
   - Then either:
     - Move the server to this new network physically (different switch port/VLAN)
     - Or configure the server's network interface to use this VLAN

4. **Verify the assignment:**
   - Go to **Settings** → **Firewall & Security** → **Firewall Rules**
   - Check that your server's network appears in the Secured zone rules

**Changing Server IP from 192.168.0.x to 192.168.3.x:**

If you want to move your server to a different network (e.g., from 192.168.0.x to 192.168.3.x), you have two options:

**Option A: Create a New Network in UniFi (Recommended)**

1. **Create new network in UniFi:**
   - Go to **Settings** → **Networks** → **Create New Network**
   - Name: `Server Network` or `gameTracker-Server`
   - Purpose: `Corporate` or `VLAN Only`
   - VLAN ID: `3` (or any unused VLAN ID)
   - Gateway IP/Subnet: `192.168.3.1/24`
   - DHCP Range: `192.168.3.10` to `192.168.3.254` (or your preferred range)
   - Firewall Zone: `Secured`
   - Click **Apply**

2. **Configure the switch port:**
   - Go to **Devices** → Find the switch your server is connected to
   - Click on the port the server is wired to
   - Set **Port Profile** to the new network you just created
   - Or set **Native Network** to the new network
   - Click **Apply**

3. **Configure server network (Ubuntu Server):**
   
   **Quick Steps:**
   ```bash
   # 1. Find your network interface name
   ip addr show
   # Note the interface name (e.g., eth0, enp0s3, ens33)
   
   # 2. Edit network configuration
   sudo nano /etc/netplan/00-installer-config.yaml
   ```
   
   **For DHCP (automatic IP):**
   ```yaml
   network:
     version: 2
     ethernets:
       eth0:  # Replace with your interface name from step 1
         dhcp4: true
   ```
   
   **For Static IP (recommended):**
   ```yaml
   network:
     version: 2
     ethernets:
       eth0:  # Replace with your interface name
         addresses:
           - 192.168.3.100/24  # Your desired IP
         routes:
           - to: default
             via: 192.168.3.1  # Gateway
         nameservers:
           addresses:
             - 8.8.8.8
             - 8.8.4.4
   ```
   
   **3. Apply the changes:**
   ```bash
   # Test first (press Enter if it works)
   sudo netplan try
   
   # Or apply directly
   sudo netplan apply
   ```
   
   **4. Verify new IP:**
   ```bash
   hostname -I
   # Should show 192.168.3.x
   
   # Test connectivity
   ping -c 3 192.168.3.1
   ```
   
   **See `SERVER-NETWORK-CONFIG.md` for detailed instructions and troubleshooting.**

**Option B: Change Existing Network's IP Range**

1. **In UniFi Controller:**
   - Go to **Settings** → **Networks**
   - Click on your current network (e.g., "Default")
   - Change **Gateway IP/Subnet** from `192.168.0.1/24` to `192.168.3.1/24`
   - Update **DHCP Range** to `192.168.3.10` - `192.168.3.254`
   - Click **Apply**

2. **On the server:**
   - If using DHCP, restart networking or wait for DHCP renewal:
     ```bash
     sudo systemctl restart systemd-networkd
     # or
     sudo dhclient -r && sudo dhclient
     ```
   - If using static IP, update the netplan config as shown in Option A

**Important Notes:**
- After changing IP, update all references to the old IP:
  - Nginx config (`server_name` directive)
  - Firewall rules (destination IP)
  - Port forwarding (forward IP)
  - Any bookmarks or documentation
- The server will need to reconnect to get the new IP
- You may need to update DNS/static IP reservations if you have any

**Important:** The zone rules below control what the Secured/Internal zone can access, NOT incoming traffic. For external access, you need the firewall rules in section 3.

**Secured Zone Rules (Outbound from Secured zone):**
- **Internal**: Allow Return (allows communication within Secured zone)
- **External**: Allow All (IMPORTANT: Server needs internet access for updates, SSL certificates, etc.)
- **Gateway**: Allow All (allows access to gateway/router - REQUIRED for DHCP and routing)
- **VPN**: Block All
- **Hotspot**: Block All
- **DMZ**: Block All
- **Guest**: Block All
- **Secured**: Allow Return (allows communication within Secured zone)

**⚠️ CRITICAL:** Make sure **Gateway** is set to **Allow All** - this is required for:
- DHCP to work (getting IP address)
- DNS resolution
- Routing to internet
- SSL certificate validation

**Note:** If your server is in the Internal zone instead, use "Internal" instead of "Secured" in the rules above.

**Note:** These zone rules don't affect incoming WAN traffic. Incoming traffic is controlled by the firewall rules in section 3 below.

### Troubleshooting: Server Lost Connection After Moving to Secured Zone

If your server lost connection after moving to Secured zone:

1. **Check Gateway Rule:**
   - Go to **Settings** → **Firewall & Security** → **Firewall Rules**
   - Find the Secured zone rules
   - **Gateway** MUST be set to **Allow All**
   - If it's blocked, change it to **Allow All** and apply

2. **Check if server still has IP:**
   - Try to SSH to the server if possible
   - Or check UniFi Controller → **Clients** to see if server appears
   - If no IP, DHCP is likely blocked

3. **Temporarily move back to Internal zone:**
   - Go to **Settings** → **Networks**
   - Click on your server's network
   - Change **Firewall Zone** back to **Internal**
   - Click **Apply**
   - Server should reconnect
   - Then fix the Secured zone rules before moving back

4. **Verify switch port configuration:**
   - Go to **Devices** → Find switch → Click the port server is on
   - Make sure port profile matches the network
   - Port should be set to the correct network/VLAN

5. **Check firewall rules aren't blocking:**
   - Go to **Settings** → **Firewall & Security** → **Firewall Rules**
   - Look for any rules blocking Secured zone traffic
   - Make sure there's a rule allowing Gateway access

6. **If using static IP, verify gateway:**
   - On server, check: `ip route show`
   - Default route should point to your gateway (e.g., 192.168.3.1)
   - If missing, update netplan config

### 2. Port Forwarding

Create port forwarding rules in UniFi:

**HTTP (Port 80):**
- Name: `gameTracker-HTTP`
- Source: `WAN`
- Destination: `WAN` (your public IP)
- Port Forward: `80`
- Forward IP: `192.168.0.100` (your server's internal IP)
- Forward Port: `80`
- Protocol: `TCP`

**HTTPS (Port 443):**
- Name: `gameTracker-HTTPS`
- Source: `WAN`
- Destination: `WAN` (your public IP)
- Port Forward: `443`
- Forward IP: `192.168.0.100` (your server's internal IP)
- Forward Port: `443`
- Protocol: `TCP`

### 3. Firewall Rules

**YES, you need firewall rules to allow external access!** The port forwarding alone isn't enough - you must create firewall rules that allow WAN traffic to reach your server.

Create firewall rules to allow external access:

**Allow HTTP/HTTPS from WAN to Server:**
- Name: `Allow-WAN-HTTP-HTTPS`
- Action: `Accept`
- Protocol: `TCP`
- Source: `WAN` (or `Any` if you want to allow from any source)
- Destination: `192.168.0.100` (your server's internal IP)
- Destination Port: `80, 443`
- **State**: `New` (or `New, Established, Related`)

**Important:** This rule must be placed BEFORE any blocking rules. Firewall rules are processed in order (top to bottom).

**Block All Other WAN Traffic to Server (Optional but Recommended):**
- Name: `Block-WAN-Other`
- Action: `Drop` or `Reject`
- Protocol: `All`
- Source: `WAN`
- Destination: `192.168.0.100` (your server's internal IP)
- Destination Port: `All`

**Note:** If you don't create the "Allow" rule above, external traffic will be blocked even with port forwarding configured.

### 4. Security Recommendations

1. **Use a Separate VLAN for Server** (if possible):
   - Create a dedicated VLAN for your server
   - Isolate it from other network devices
   - Only allow necessary communication

2. **Enable GeoIP Blocking** (optional):
   - Block traffic from countries you don't expect users from
   - Reduces attack surface

3. **Enable Intrusion Detection/Prevention**:
   - Enable IDS/IPS in UniFi Security settings
   - Monitor for suspicious activity

4. **Regular Updates**:
   - Keep UniFi Controller updated
   - Keep server OS updated

## Testing

After configuration:

1. Test internal access: `http://192.168.0.100`
2. Test external access: `http://YOUR_PUBLIC_IP` (from outside your network)
3. Verify HTTPS redirect works
4. Check firewall logs for any blocked traffic

## Troubleshooting

**Can't access from outside:**
- Verify port forwarding rules are active
- Check firewall rules aren't blocking traffic
- Verify server firewall (UFW) allows ports 80/443
- Check if your ISP blocks incoming ports

**SSL certificate issues:**
- Ensure port 80 is accessible for Let's Encrypt verification
- Check DNS settings if using a domain name
- Verify certificate paths in Nginx config

## Next Steps

After UniFi configuration:
1. Configure UFW firewall on server (see `SECURITY-SETUP.md`)
2. Set up SSL certificates with Certbot
3. Test all security measures
4. Monitor logs for suspicious activity

