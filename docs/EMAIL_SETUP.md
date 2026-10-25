# Sending real email (SMTP)

Until you do this, every email (verification links, password resets, submission confirmations) is only written to
`storage\logs\mail.log`. Students will not receive anything.

## 1. Get SMTP details from your email provider

You need: a host name, a port, a username, a password, and a "from" address you are allowed to send from.

| Setting | Typical value |
|---|---|
| Host | `smtp.yourprovider.com` |
| Port | `587` with `tls` (STARTTLS), or `465` with `ssl` |
| Username / password | from the provider (many require an "app password" or "SMTP key", not your login password) |
| From address | an address on your own domain, for example `no-reply@yourdomain.org` |

Notes
- **For production use a proper sending service or your organisation's mail server**, and set up SPF and DKIM for your domain
  (your provider explains how). Without them, messages often land in spam or are rejected.
- A personal mailbox (for example Gmail) can be used for testing. It normally needs 2-step verification switched on and an
  *app password* (16 characters; type it without spaces). Sending limits are low and not suitable for a live portal.
- Never commit `.env` to Git and never share the password in chat or email.

## 2. Put them in `.env`

```
MAIL_DRIVER=smtp
SMTP_HOST=smtp.yourprovider.com
SMTP_PORT=587
SMTP_USER=your-smtp-username
SMTP_PASSWORD=your-smtp-password
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@yourdomain.org
MAIL_FROM_NAME="IHA Portal"
```
Use `SMTP_PORT=465` and `SMTP_ENCRYPTION=ssl` if your provider asks for that. Leave `SMTP_USER` empty only for a server that
needs no login. Also check `APP_URL` is the address students will actually open (it is used inside the emailed links).

## 3. Test it

Make sure the libraries are installed (`composer install --no-dev`, once), then:

```
D:\php\php.exe bin\mail-test.php your.own@address.com
```

It prints `Sent to ...` on success, or the provider's error (wrong password, blocked port, certificate problem...). Check the spam
folder if nothing arrives.

## 4. Then try the real flows

Register a new student with an address you can read, open the verification link from the email, and try "Forgot your password?".

## If it fails

- *Could not authenticate*: wrong username/password, or the provider needs an app password.
- *Could not connect / timed out*: the port is blocked (try the other port, or another network), or the host name is wrong.
- *Certificate errors*: the server clock is wrong, or the host name does not match the certificate.
- Details are also written to PHP's error log, so a failed send never breaks registration or submission; the student can use "Resend verification link".
