# Packet Tracer Network Update Based on BPA Diagram

## 1. Overview
This document provides step-by-step instructions to update your Cisco Packet Tracer network topology to align with your Business Process Automation (BPA) system. It includes device additions, connections, IP assignments, and sample configurations.

---


## 2. BPA Modules and Server Mapping
Your current topology already includes several BPA modules as servers. Below is a mapping of your existing servers and the BPA modules they represent, as well as the modules you still need to add:

### Existing Servers (Already Created)
- ESS (SRV2-HR2-ESS)
- COMPETENCY MANAGEMENT (SRV3-HR2-COMPETENCY)
- TRAINING MANAGEMENT (SRV4-HR2-TRAINING)
- LEARNING MANAGEMENT (SRV5-HR2-LEARNING)
- SUCCESSION PLANNING (SRV6-HR2-SUCCESSION)

### Modules to Add
- PAYROLL
- TIME AND ATTENDANCE
- LEAVE MANAGEMENT
- CORE HUMAN (HR Core)

> Note: Security and access control functions (such as user provisioning, RBAC, and policy enforcement) are handled by your R1-HR2 router. Therefore, a separate HR2-ACCESS server is not required in your topology.

> Note: The last server you created (SRV6-HR2-SUCCESSION) uses IP address 192.168.40.15 with gateway 192.168.40.1. Please use this subnet for all new servers.

---

## 3. Step-by-Step Instructions

### 3.1. Add Servers
1. In Packet Tracer, select the “End Devices” icon.
2. Drag “Server-PT” onto the workspace for each BPA module.
3. Rename each server (Config tab > Display Name).

### 3.2. Connect Servers to Main Switch
1. Select the “Connections” (lightning bolt) icon.
2. Use “Copper Straight-Through” cable.
3. Connect each server to your main switch (e.g., SW2-HR2).


### 3.3. Assign IP Addresses
Assign static IPs in the 192.168.40.x subnet, using your gateway 192.168.40.1. Here is a suggested mapping (update as needed to avoid conflicts with existing devices):

| Server Name           | Example IP        | Status         |
|-----------------------|-------------------|----------------|
| ESS                   | 192.168.40.12     | Already exists |
| COMPETENCY MANAGEMENT | 192.168.40.13     | Already exists |
| TRAINING MANAGEMENT   | 192.168.40.14     | Already exists |
| SUCCESSION PLANNING   | 192.168.40.15     | Already exists |
| LEARNING MANAGEMENT   | 192.168.40.16     | Already exists |
| PAYROLL               | 192.168.40.17     | To add         |
| TIME AND ATTENDANCE   | 192.168.40.18     | To add         |
| LEAVE MANAGEMENT      | 192.168.40.19     | To add         |
| CORE HUMAN            | 192.168.40.20     | To add         |


- Subnet Mask: 255.255.255.0
- Default Gateway: 192.168.40.1 (router interface)

#### How to Assign:
- Click server > Config tab > FastEthernet0
- Enter IP, subnet mask, and gateway

### 3.4. Label Each Server
- Use the “Text” tool to label each server for clarity.

### 3.5. Enable Services (Optional)
- Click server > Services tab
- Enable HTTP, FTP, or other services as needed

### 3.6. Simulate Inter-Module Communication
- Use the “Add Simple PDU” tool to send test packets between servers
- Use PCs to access server services (e.g., web browser to server IP)

### 3.7. Configure Access Control (Optional)
- On the router, configure Access Control Lists (ACLs) to restrict access

#### Example ACL (on router):
```
access-list 100 permit ip host 192.168.1.14 host 192.168.1.10
access-list 100 deny ip any any
interface GigabitEthernet0/1
 ip access-group 100 in
```
This allows ESS (192.168.40.12) to access PAYROLL (192.168.40.17) only (update IPs as needed for your setup).

---

## 4. Saving and Testing
- Save your project (File > Save As)
- Use Simulation mode to test connectivity

---

## 5. Tips
- Use the “Notes” tool to document your topology
- Use the “Simulation” mode to visualize data flow
- Configure each server with different services to simulate real applications

---

## 6. References
- Cisco Packet Tracer User Guide
- Your BPA system documentation

---

## 7. Example CLI Configuration for New Servers (for reference)

If you are using a Cisco IOS device as a server (or for automation/scripting), here is a sample configuration for assigning an IP address and gateway:

```
enable
configure terminal
interface vlan 1
ip address 192.168.40.19 255.255.255.0
no shutdown
exit
ip default-gateway 192.168.40.1
end
write memory
```

- Replace `vlan 1` with the correct VLAN if needed.
- Replace `192.168.40.19` with the IP address for your new server.
- For Server-PT in Packet Tracer, use the GUI as described above.

---

## 7. CLI Configuration Command for LEAVE MANAGEMENT Server (Switch Example)

If you are configuring a Cisco switch (like SW2-SERVER) to simulate the LEAVE MANAGEMENT server interface, use the following commands (assuming FastEthernet0/19 is the port connected to the LEAVE MANAGEMENT server):

```
enable
configure terminal
interface vlan 1
ip address 192.168.40.19 255.255.255.0
no shutdown
exit
ip default-gateway 192.168.40.1
end
write memory
```

- This configures VLAN 1 with IP 192.168.40.19 and sets the default gateway.
- For Server-PT in Packet Tracer, use the GUI as described above.
- If you are configuring a physical port, you do not assign an IP to the port itself; the server gets its IP via its own configuration.