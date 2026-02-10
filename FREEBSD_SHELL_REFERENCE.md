# FreeBSD/OPNsense Shell Command Reference

## Shell Information

**Default Interactive Shell**: tcsh (C shell)
**Script Execution Shell**: sh (when `#!/bin/sh` shebang used)
**Agent Script Runs In**: sh (Bourne shell) - uses `#!/bin/sh`

## CRITICAL: Interactive vs Script Shells

### When typing commands in SSH session:
- You're in **tcsh** (C shell)
- Variable syntax: `set var = value`
- Command substitution: `set result = \`command\``  
- NO `$()` syntax!
- NO `export` command!

### When agent script runs via cron:
- Script runs in **sh** (Bourne shell) because of `#!/bin/sh`
- Variable syntax: `var=value` (no spaces!)
- Command substitution: `result=$(command)` or `` result=`command` ``
- Can use `export`

## FreeBSD tcsh Commands (Interactive Shell)

### Variables
```tcsh
# Set variable
set myvar = "value"
set myvar = value

# Use variable
echo $myvar
echo ${myvar}

# Unset variable
unset myvar
```

### Command Substitution
```tcsh
# Using backticks (works in tcsh)
set output = `date`
set files = `ls -la`

# Using $() does NOT work in tcsh!
# This fails: set output = $(date)
```

### Conditionals
```tcsh
# If statement
if ( condition ) then
    commands
else
    commands
endif

# Test file exists
if ( -f /path/to/file ) then
    echo "File exists"
endif
```

### Loops
```tcsh
# Foreach loop
foreach file ( *.txt )
    echo $file
end

# While loop
while ( condition )
    commands
end
```

## FreeBSD sh Commands (Script Shell)

### Variables
```sh
# Set variable (NO SPACES around =)
myvar="value"
myvar=value

# Use variable
echo "$myvar"
echo "${myvar}"

# Export for subprocesses
export MYVAR="value"
```

### Command Substitution
```sh
# Modern syntax (preferred)
output=$(date)
files=$(ls -la)

# Old syntax (also works)
output=`date`
files=`ls -la`
```

### String Operations
```sh
# Parameter expansion
${var}           # Value of var
${var:-default}  # Use default if var unset
${var#pattern}   # Remove shortest match from beginning
${var##pattern}  # Remove longest match from beginning  
${var%pattern}   # Remove shortest match from end
${var%%pattern}  # Remove longest match from end

# Examples
result="success|||output data"
status="${result%%|||*}"      # Gets "success"
output="${result#*|||}"       # Gets "output data"
```

### Conditionals
```sh
# If statement
if [ condition ]; then
    commands
else
    commands
fi

# Test file exists
if [ -f /path/to/file ]; then
    echo "File exists"
fi

# Test command success
if command; then
    echo "Success"
fi
```

## Common Commands (Work in Both Shells)

### File Operations
```sh
# List files
ls -la

# Find files
find /path -name "pattern"

# Copy/move/delete
cp source dest
mv source dest
rm file
rm -rf directory

# Check if file exists
test -f /path/to/file && echo "exists"
[ -f /path/to/file ] && echo "exists"

# Create directory
mkdir -p /path/to/dir

# Change permissions
chmod +x script.sh
chmod 755 file
chown user:group file
```

### Process Management
```sh
# List processes
ps aux
ps aux | grep pattern

# Kill process
kill PID
kill -9 PID
pkill -f pattern
pkill -9 -f pattern

# Background process
command &
nohup command &

# Check if process running
pgrep -f pattern
```

### Text Processing
```sh
# grep - search text
grep "pattern" file
grep -r "pattern" /path
grep -i "pattern" file          # case insensitive
grep -v "pattern" file          # invert match
grep -A 5 "pattern" file        # 5 lines after
grep -B 5 "pattern" file        # 5 lines before

# sed - stream editor
sed 's/old/new/' file           # replace first occurrence
sed 's/old/new/g' file          # replace all
sed -i 's/old/new/g' file       # edit file in-place
sed -n '10,20p' file            # print lines 10-20
sed '10d' file                  # delete line 10

# awk - pattern scanning
awk '{print $1}' file           # print first field
awk -F'|' '{print $1}' file     # custom delimiter
awk '/pattern/ {print $0}' file # print matching lines
awk 'NR==10' file               # print line 10

# cut - cut fields
echo "a|b|c" | cut -d'|' -f1    # outputs: a
echo "a|b|c" | cut -d'|' -f2    # outputs: b

# tr - translate characters
echo "text" | tr 'a-z' 'A-Z'    # uppercase
echo "text" | tr -d '\n'        # remove newlines
```

### Network Commands
```sh
# curl - HTTP requests
curl https://example.com
curl -o file.txt https://example.com/file.txt
curl -k https://example.com              # ignore SSL
curl -s https://example.com              # silent
curl -X POST -d "data" https://example.com
curl -H "Header: value" https://example.com

# fetch - FreeBSD alternative to wget
fetch https://example.com/file.txt
fetch -o output.txt https://example.com/file.txt
fetch -q https://example.com             # quiet

# Test connectivity
ping -c 4 8.8.8.8
traceroute 8.8.8.8
host google.com
dig google.com

# Check listening ports
sockstat -4 -l
sockstat -4 -l | grep :22
netstat -an | grep LISTEN
```

### Service Management (FreeBSD)
```sh
# Check service status
service servicename status

# Start/stop/restart service
service servicename start
service servicename stop  
service servicename restart

# Enable service at boot
sysrc servicename_enable="YES"

# RC scripts
/usr/local/etc/rc.d/servicename start
```

### Cron
```sh
# View crontab
crontab -l

# Edit crontab (DON'T do this - will open editor in SSH)
crontab -e

# Set crontab from command (DANGEROUS - replaces entire crontab!)
echo "* * * * * /path/to/script" | crontab -

# Add to crontab (append, don't replace)
(crontab -l 2>/dev/null; echo "* * * * * /path/to/script") | crontab -

# Remove crontab
crontab -r

# Cron format: minute hour day month weekday command
# * * * * * means every minute
# */5 * * * * means every 5 minutes
# 0 * * * * means every hour at :00
```

## Database Commands (MySQL/MariaDB)

```sh
# Connect and run query
mysql -u username -p'password' database -e "SELECT * FROM table;"

# Multi-line query
mysql -u username -p'password' database << EOF
SELECT * FROM table;
UPDATE table SET col=val WHERE id=1;
EOF

# Escape quotes in queries
mysql -u username -p'password' database -e "INSERT INTO table VALUES ('value with \"quotes\"');"
```

## OPNsense Specific

### Firewall Rules (pfctl)
```sh
# Show current rules
pfctl -sr

# Show rules in specific anchor
pfctl -sr -a anchorname

# List anchors
pfctl -sA

# Add rule to anchor (temporary!)
echo "pass in quick on igc0 proto tcp from 1.2.3.4 to any port 22" | pfctl -a anchorname -f -

# Clear rules in anchor
pfctl -a anchorname -F rules

# Reload firewall config
/usr/local/etc/rc.filter_configure
```

### OPNsense Configuration
```sh
# Get version
opnsense-version

# Update system
/usr/local/sbin/opnsense-update -bkf

# Configuration file
/conf/config.xml

# Backup config
cp /conf/config.xml /root/config-backup-$(date +%Y%m%d).xml
```

## Common Pitfalls

### 1. Shell Type Confusion
❌ **WRONG**: Trying bash syntax in tcsh interactive shell
```tcsh
$ result=$(date)          # FAILS in tcsh
$ export VAR=value        # FAILS in tcsh
```

✅ **RIGHT**: Use tcsh syntax when typing commands
```tcsh
$ set result = `date`     # Works in tcsh
$ setenv VAR value        # Works in tcsh
```

### 2. Newlines in Variables
❌ **WRONG**: Unquoted variable with newlines
```sh
echo $var | command       # Breaks if $var has newlines
```

✅ **RIGHT**: Always quote variables
```sh
echo "$var" | command     # Preserves newlines
```

### 3. Pipe Creates Subshell
❌ **WRONG**: Setting variable in pipe subshell
```sh
echo "data" | while read line; do
    result=$line          # Lost when pipe ends!
done
echo $result              # Empty!
```

✅ **RIGHT**: Use heredoc or process substitution
```sh
while read line; do
    result=$line
done << EOF
data
EOF
echo $result              # Works!
```

### 4. Crontab Replacement
❌ **WRONG**: This REPLACES entire crontab
```sh
echo "* * * * * /script" | crontab -
```

✅ **RIGHT**: Append to existing crontab
```sh
(crontab -l 2>/dev/null; echo "* * * * * /script") | crontab -
```

### 5. JSON in Shell Variables
❌ **WRONG**: Newlines break JSON
```sh
result="success
with newline"
curl -d "{\"result\": \"$result\"}"    # Invalid JSON!
```

✅ **RIGHT**: Extract only what you need
```sh
result_full="success|||output\nwith\nnewlines"
result=$(echo "$result_full" | awk -F'|' '{print $1}')  # Just "success"
curl -d "{\"result\": \"$result\"}"    # Valid JSON
```

---

**Last Updated**: October 14, 2025  
**System**: FreeBSD 14.3-RELEASE-p4, OPNsense 25.7.5
