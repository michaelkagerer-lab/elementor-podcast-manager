#!/usr/bin/env python3
"""Local media host for tests/media/ (test runs only).

Usage: mediaserver.py <port|0> <logfile>
Prints "listening on 127.0.0.1:<port>" once it accepts connections.

Every body is a deterministic sequence of 144-byte silent MPEG-1 Layer III
frames whose padding carries the frame number, so a file put together from
the wrong pieces is never byte-identical to the original
(epm_md_frames() in tests/media/downloads.php builds the same bytes).

Endpoints (GET and HEAD):
  /len/<bytes>/<name>              200 with Content-Length; Range -> 206
  /id3/<bytes>/<name>              like /len, but an ID3v2 tag and silent frames
                                   without a single newline byte (what made
                                   getimagesize() read a whole MP3 into memory)
  /chunked/<bytes>/<name>          200, Transfer-Encoding: chunked, no length
  /slow/<bytes_per_s>/<bytes>/<name>  like /len, throttled; Range -> 206
  /norange/<bytes_per_s>/<bytes>/<name>  throttled, ignores Range (always 200)
  /stall/<name>                    Content-Length 10 MB, sends 4 KB, then nothing
  /status/<code>/<name>            <code> with a small HTML page (429/503: Retry-After: 120)
  /html/<name>                     200 text/html login page
  /octet/<name>                    200 application/octet-stream, 200000 random bytes
  /redirect/<code>/<url-encoded target>  a redirect to the target

Each request appends one JSON line to <logfile> when it ends:
  {"path", "range", "status", "sent", "hung_up", "seconds"}
"""
import json
import os
import struct
import sys
import threading
import time
import urllib.parse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

FRAME = 144
LOCK = threading.Lock()
LOG = sys.argv[2] if len(sys.argv) > 2 else os.devnull


def frames(offset, length):
    """Bytes [offset, offset+length) of the endless frame sequence."""
    first = offset // FRAME
    last = (offset + length + FRAME - 1) // FRAME
    out = bytearray()
    for n in range(first, last):
        out += b"\xff\xfb\x18\xc0" + struct.pack(">I", n) + b"\x00" * (FRAME - 8)
    start = offset - first * FRAME
    return bytes(out[start:start + length])


ID3 = b"ID3\x03\x00\x00\x00\x00\x00\x10" + b"\x00" * 16
SILENT = b"\xff\xfb\x18\xc0" + b"\x00" * (FRAME - 4)


def id3(offset, length):
    """Bytes [offset, offset+length) of an ID3 tag followed by silent frames."""
    out = bytearray()
    if offset < len(ID3):
        out += ID3[offset:offset + length]
    pos = max(0, offset - len(ID3))
    while len(out) < length:
        start = pos % FRAME
        piece = SILENT[start:start + length - len(out)]
        out += piece
        pos += len(piece)
    return bytes(out)


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, fmt, *args):
        pass

    def record(self, status, sent, hung_up, started):
        line = {
            "path": self.path,
            "range": self.headers.get("Range", ""),
            "status": status,
            "sent": sent,
            "hung_up": hung_up,
            "seconds": round(time.time() - started, 3),
        }
        with LOCK:
            with open(LOG, "a") as fh:
                fh.write(json.dumps(line) + "\n")

    def do_HEAD(self):
        self.handle_request(head=True)

    def do_GET(self):
        self.handle_request(head=False)

    def body(self, total, offset, bps, chunked, head, started, status, source=frames):
        """Send bytes [offset, total) of the sequence."""
        sent = 0
        length = total - offset
        try:
            if not head:
                block = 64 * 1024 if not bps else max(1, bps // 10)
                begin = time.time()
                while sent < length:
                    n = min(block, length - sent)
                    data = source(offset + sent, n)
                    if chunked:
                        self.wfile.write(b"%x\r\n" % len(data) + data + b"\r\n")
                    else:
                        self.wfile.write(data)
                    sent += n
                    if bps:
                        delay = sent / bps - (time.time() - begin)
                        if delay > 0:
                            time.sleep(delay)
                if chunked:
                    self.wfile.write(b"0\r\n\r\n")
                self.wfile.flush()
            self.record(status, sent, False, started)
        except (BrokenPipeError, ConnectionResetError):
            self.record(status, sent, True, started)

    def handle_request(self, head):
        started = time.time()
        parts = self.path.split("?")[0].strip("/").split("/")
        kind = parts[0] if parts else ""
        try:
            if kind in ("len", "id3", "slow", "norange"):
                if kind in ("len", "id3"):
                    bps, total = 0, int(parts[1])
                else:
                    bps, total = int(parts[1]), int(parts[2])
                offset = 0
                status = 200
                ranged = self.headers.get("Range", "")
                if kind != "norange" and ranged.startswith("bytes=") and ranged.endswith("-"):
                    offset = int(ranged[6:-1])
                    if offset >= total:
                        self.send_response(416)
                        self.send_header("Content-Range", "bytes */%d" % total)
                        self.send_header("Content-Length", "0")
                        self.end_headers()
                        self.record(416, 0, False, started)
                        return
                    status = 206
                self.send_response(status)
                self.send_header("Content-Type", "audio/mpeg")
                self.send_header("Accept-Ranges", "none" if kind == "norange" else "bytes")
                self.send_header("Content-Length", str(total - offset))
                if status == 206:
                    self.send_header("Content-Range", "bytes %d-%d/%d" % (offset, total - 1, total))
                self.end_headers()
                self.body(total, offset, bps, False, head, started, status, id3 if kind == "id3" else frames)
            elif kind == "chunked":
                total = int(parts[1])
                self.send_response(200)
                self.send_header("Content-Type", "audio/mpeg")
                self.send_header("Transfer-Encoding", "chunked")
                self.end_headers()
                self.body(total, 0, 0, True, head, started, 200)
            elif kind == "stall":
                self.send_response(200)
                self.send_header("Content-Type", "audio/mpeg")
                self.send_header("Content-Length", str(10 * 1024 * 1024))
                self.end_headers()
                sent = 0
                if not head:
                    self.wfile.write(frames(0, 4096))
                    self.wfile.flush()
                    sent = 4096
                    # Silence until the client hangs up (or 10 minutes).
                    self.connection.settimeout(1.0)
                    for _ in range(600):
                        try:
                            if self.connection.recv(1) == b"":
                                break
                        except OSError:
                            continue
                self.record(200, sent, True, started)
            elif kind == "status":
                code = int(parts[1])
                page = ("<html><body><h1>%d</h1></body></html>" % code).encode()
                self.send_response(code)
                self.send_header("Content-Type", "text/html")
                self.send_header("Content-Length", str(len(page)))
                if code in (429, 503):
                    self.send_header("Retry-After", "120")
                self.end_headers()
                if not head:
                    self.wfile.write(page)
                self.record(code, len(page), False, started)
            elif kind == "html":
                page = b"<!DOCTYPE html><html><head><title>Log in</title></head><body><form>Please log in to download this episode.</form></body></html>"
                self.send_response(200)
                self.send_header("Content-Type", "text/html; charset=UTF-8")
                self.send_header("Content-Length", str(len(page)))
                self.end_headers()
                if not head:
                    self.wfile.write(page)
                self.record(200, len(page), False, started)
            elif kind == "octet":
                data = os.urandom(200000)
                self.send_response(200)
                self.send_header("Content-Type", "application/octet-stream")
                self.send_header("Content-Length", str(len(data)))
                self.end_headers()
                if not head:
                    self.wfile.write(data)
                self.record(200, len(data), False, started)
            elif kind == "redirect":
                code = int(parts[1])
                target = urllib.parse.unquote("/".join(parts[2:]))
                self.send_response(code)
                self.send_header("Location", target)
                self.send_header("Content-Length", "0")
                self.end_headers()
                self.record(code, 0, False, started)
            else:
                self.send_response(404)
                self.send_header("Content-Length", "0")
                self.end_headers()
                self.record(404, 0, False, started)
        except (BrokenPipeError, ConnectionResetError):
            self.record(0, 0, True, started)


if __name__ == "__main__":
    port = int(sys.argv[1]) if len(sys.argv) > 1 else 0
    ThreadingHTTPServer.daemon_threads = True
    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    sys.stdout.write("listening on 127.0.0.1:%d\n" % server.server_address[1])
    sys.stdout.flush()
    server.serve_forever()
