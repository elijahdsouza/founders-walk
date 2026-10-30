# Founders Walk matcher

A one-page site for Uncommon Collective's Founders Walk. Attendees scan a QR code,
add their name, and after the intro circle get matched with two people they haven't
walked with yet. Each match shows three question cards (Icebreaker, Founder journey,
Networking) that they can reshuffle. When they finish, the site emails them a summary
of who they met, and when you end the walk you get a roll-up.

It runs on the Hostinger hosting you already have: plain files, a small SQLite
database file and PHP's built-in mail. No paid services.

## Files

| File | What it is |
|---|---|
| `index.html` | The whole site: attendee screens, plus the host screen at `?host` |
| `api.php` | Saves people, does the matching, sends the emails |
| `config.sample.php` | Settings to copy into `config.php` |
| `qrcode.min.js` | QR code library (MIT, see `qrcode.LICENSE.txt`) |
| `.htaccess`, `data/.htaccess` | Block the settings and the database from the web |

## Set up on a Hostinger VPS (about 20 minutes, once)

The host screen (`?host`) and the attendee screens are the same site and share one
database, so whatever the host does shows up on everyone's phone within a few seconds.

1. **Point the address at the VPS.** In hPanel, **Domains > DNS / Nameservers** for
   `uncommoncollectiveau.com`, add an **A record**: name `walk`, value = your VPS's IP
   address (shown on the VPS overview page). It can take a few minutes to work.
2. **Make the sending mailbox** `walk@uncommoncollectiveau.com` (hPanel **Emails**), and
   note its password. If your email is on Microsoft 365, make it there instead.
3. **Upload this folder to the VPS.** On your computer, download this repo from GitHub
   (**Code > Download ZIP**), unzip it, then in a terminal:
   ```sh
   scp -r founders-walk root@YOUR_VPS_IP:/root/
   ```
   (Hostinger's **Browser terminal** on the VPS page works for step 4, but not for uploading.)
4. **Run the setup.** Log in (`ssh root@YOUR_VPS_IP`) and run:
   ```sh
   cd /root/founders-walk && bash deploy/setup-vps.sh
   ```
   It asks for the web address, a host PIN and the mailbox password, then installs the
   web server, blocks the settings and database from the web, turns on HTTPS and
   connects email. To update the site later, upload the folder again and rerun it;
   your walks and settings are kept.
5. **Test it** the same way as in step 7 below, at `https://walk.uncommoncollectiveau.com`.

## Set up on Hostinger shared hosting (about 15 minutes, once)

1. **Check your plan.** In hPanel, your site needs to be on a *Web hosting* plan
   (Premium, Business or similar). Hostinger's *Website Builder* plans can't run
   PHP files. Under **Advanced > PHP Configuration**, choose PHP 8.2 or newer.
2. **Make the subdomain.** **Domains > Subdomains**: create `walk`
   (giving `walk.uncommoncollectiveau.com`). Hostinger makes a folder for it,
   usually `public_html/walk`.
3. **Turn on HTTPS.** **Security > SSL**: install the free SSL for the subdomain.
4. **Make the sending mailbox.** **Emails**: create `walk@uncommoncollectiveau.com`.
   If your domain's email is on Microsoft 365 or Google rather than Hostinger, ask
   whoever manages your DNS to add Hostinger to the SPF record, or summaries may land
   in spam. Hostinger's support chat can give you the exact record for your account.
5. **Upload.** **Files > File Manager**, open the subdomain's folder and upload
   everything in this folder, including the hidden `.htaccess` files and the
   `data` folder.
6. **Settings.** In File Manager, copy `config.sample.php` to `config.php` and edit it:
   - `FW_HOST_PIN`: a PIN only you know (8+ characters)
   - `FW_FROM_ADDRESS`: the mailbox from step 4
   - `FW_HOST_EMAIL`: where your roll-up goes
7. **Test it.**
   - Open `https://walk.uncommoncollectiveau.com/?host`, enter your PIN and start a test walk.
   - Open the sign-up link and sign up with your own email. Then scan the QR code
     to check in. Have a second phone (or a private browser tab) do the same.
   - Tap **Start matching**, go through both matches, and check the summary email
     arrives (look in spam too).
   - Open `https://walk.uncommoncollectiveau.com/config.php` and `.../data/`: both
     should say *Forbidden*.
   - End the test walk. You can leave it; it deletes itself after 30 days.

## Before and on the day

**Before the walk (days ahead)**
1. Open `?host`, tap **Start a new walk** with the walk's date.
2. Tap **Copy sign-up link** and share it (Luma confirmation, WhatsApp, Instagram).
   People add their name, one-liner, link and email. Signing up does *not* get
   anyone matched yet.

**On the day**
1. Do intros in the circle as usual. With a big group, keep each intro to a name
   and one line; the matching does the real introductions.
2. Show the QR code on your phone. Scanning it checks people in. Anyone who didn't
   sign up can sign up and check in in one go. If someone signed up on a different
   phone or browser, they use the same email and the site finds their sign-up.
   Everyone who checks in lands in the lobby: they see who else is here while they wait.
3. Watch the count: "12 here, 4 signed up, not here". Tap **Check in** next to anyone
   who can't scan (flat battery, no signal).
4. When everyone's in, tap **Start matching**, then tap again to confirm. Only people
   who are checked in are matched; no-shows are left out.
5. Late arrivals scan the QR code and are slotted into a pair straight away.
6. At the end, tap **End walk & send summaries**. Everyone who checked in and left
   an email gets their summary; you get the roll-up. No-shows get nothing.

The QR code holds a check-in key that the shared sign-up link doesn't, so nobody
can check in from home. Don't post a photo of the QR code publicly.

## How matching works

- Only people who are checked in are matched. Two rounds are drawn when you tap
  Start (one round if fewer than 4 people).
- Pairs; an odd number makes one group of three.
- Round 2 avoids repeating anyone from round 1 wherever the numbers allow.
- **Reshuffle** redraws both rounds for everyone who hasn't been emailed yet.
- The questions are the same for everyone in a round, so both people in a pair see
  the same three cards. Each person can reshuffle any card on their own phone.

## Privacy

Attendees tick a consent box before joining. Matches see only name, role, one-liner
and link; emails are never shown to other attendees. Every walk and everyone's
details are deleted 30 days after the walk starts (`FW_RETENTION_DAYS`). If you
want to confirm this is enough for your situation, a privacy adviser can check it
against the Australian Privacy Principles.

## Editing the questions

They're at the top of the script in `index.html` (`const Q = ...`), grouped by
category. Add or reword lines and upload the file again.

## Testing locally

```sh
cp config.sample.php config.php   # set FW_MAIL_MODE to 'log' and a test PIN
php -S 127.0.0.1:8099
```

Emails are then written to `data/outbox/` instead of being sent. Add `?demo` to
the URL for a version with example people that needs no server.
