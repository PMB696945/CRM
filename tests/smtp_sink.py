"""A tiny SMTP server for tests: saves each message received to a folder.

    python3 tests/smtp_sink.py <port> <folder>

Supports AUTH LOGIN (any username/password) so the CRM's login code is exercised.
"""
import asyncore, base64, os, smtpd, sys, time, warnings

warnings.filterwarnings("ignore")
port, folder = int(sys.argv[1]), sys.argv[2]
os.makedirs(folder, exist_ok=True)


class Channel(smtpd.SMTPChannel):
    def smtp_EHLO(self, arg):
        self.push("250-localhost\r\n250-AUTH LOGIN\r\n250 8BITMIME")
        self.seen_greeting = arg
        self.extended_smtp = True

    def smtp_AUTH(self, arg):
        self.push("334 VXNlcm5hbWU6")
        self.set_terminator(b"\r\n")
        self.auth_step = 1

    def found_terminator(self):
        step = getattr(self, "auth_step", 0)
        if step:
            line = b"".join(self.received_lines if hasattr(self, "received_lines") else [])
            self.received_lines = []
            if step == 1:
                self.auth_step = 2
                self.push("334 UGFzc3dvcmQ6")
            else:
                self.auth_step = 0
                self.push("235 Authentication successful")
            return
        super().found_terminator()

    def collect_incoming_data(self, data):
        if getattr(self, "auth_step", 0):
            self.received_lines = getattr(self, "received_lines", []) + [data]
            return
        super().collect_incoming_data(data)


class Sink(smtpd.SMTPServer):
    channel_class = Channel

    def process_message(self, peer, mailfrom, rcpttos, data, **kwargs):
        name = os.path.join(folder, "%f.eml" % time.time())
        with open(name, "wb") as f:
            f.write(("X-Rcpt: %s\r\n" % ",".join(rcpttos)).encode() + (data if isinstance(data, bytes) else data.encode()))


Sink(("127.0.0.1", port), None, decode_data=False)
asyncore.loop()
