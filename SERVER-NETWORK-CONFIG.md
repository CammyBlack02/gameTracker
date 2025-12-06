# Server Network Configuration Guide

## Changing Server IP from 192.168.0.x to 192.168.3.x

### Step 1: Find Your Network Interface Name

```bash
ip addr show
# or
ip link show
```

Look for your wired Ethernet interface. Common names:
- `eth0`
- `enp0s3`
- `ens33`
- `enp2s0`

Note the interface name (we'll use `eth0` as example, replace with yours).

### Step 2: Check Current Network Configuration

```bash
# Check current IP
hostname -I

# Check current netplan config
ls -la /etc/netplan/
cat /etc/netplan/*.yaml
```

### Step 3: Edit Network Configuration

```bash
sudo nano /etc/netplan/00-installer-config.yaml
```

**If using DHCP (automatic IP from UniFi):**
```yaml
network:
  version: 2
  ethernets:
    eth0:  # Replace with your interface name
      dhcp4: true
```

**If using Static IP (recommended for servers):**
```yaml
network:
  version: 2
  ethernets:
    eth0:  # Replace with your interface name
      addresses:
        - 192.168.3.100/24  # Your desired IP address
      routes:
        - to: default
          via: 192.168.3.1  # Gateway (usually .1)
      nameservers:
        addresses:
          - 8.8.8.8
          - 8.8.4.4
```

**Important:** 
- Replace `eth0` with your actual interface name
- Replace `192.168.3.100` with your desired IP (make sure it's not in DHCP range)
- Replace `192.168.3.1` with your gateway IP (usually the router's IP)

### Step 4: Apply the Configuration

```bash
# Test the configuration first
sudo netplan try

# If test is successful (press Enter), or apply directly:
sudo netplan apply
```

### Step 5: Verify New IP

```bash
# Check new IP address
ip addr show eth0
# or
hostname -I

# Test connectivity
ping -c 3 192.168.3.1  # Ping gateway
ping -c 3 8.8.8.8      # Ping Google DNS
```

### Step 6: If Using Static IP - Update Firewall Rules

After changing the IP, update your UniFi firewall rules and port forwarding to use the new IP (e.g., `192.168.3.100` instead of `192.168.0.100`).

### Troubleshooting

**Can't connect after change:**
```bash
# Check if interface is up
ip link show eth0

# Bring interface down and up
sudo ip link set eth0 down
sudo ip link set eth0 up

# Or restart networking
sudo systemctl restart systemd-networkd
```

**Configuration syntax error:**
```bash
# Validate netplan syntax
sudo netplan --debug apply
```

**Still on old network:**
- Make sure UniFi switch port is configured for the new network (192.168.3.x)
- Check that the server is actually connected to the correct switch port
- Verify VLAN configuration in UniFi

### Example: Complete Static IP Configuration

If your interface is `eth0` and you want IP `192.168.3.100`:

```bash
sudo nano /etc/netplan/00-installer-config.yaml
```

Paste this (adjust interface name if different):
```yaml
network:
  version: 2
  renderer: networkd
  ethernets:
    eth0:
      addresses:
        - 192.168.3.100/24
      routes:
        - to: default
          via: 192.168.3.1
      nameservers:
        addresses:
          - 8.8.8.8
          - 8.8.4.4
```

Save (Ctrl+O, Enter, Ctrl+X), then:
```bash
sudo netplan apply
```

