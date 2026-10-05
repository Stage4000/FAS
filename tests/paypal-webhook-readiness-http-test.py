"""Isolated HTTP check: a setup probe stays quiet while other unsigned events alert."""

import json
import os
from contextlib import closing
from pathlib import Path
import shutil
import socket
import sqlite3
import subprocess
import tempfile
import time
import urllib.error
import urllib.request


ROOT = Path(__file__).resolve().parents[1]
FILES = [
    "api/paypal-webhook.php",
    "src/config/Database.php",
    "src/models/Order.php",
    "src/models/Product.php",
    "src/integrations/PayPalAPI.php",
    "src/payments/PayPalWebhookSetup.php",
    "src/utils/ErrorMonitor.php",
    "src/utils/Analytics.php",
    "src/utils/Timezone.php",
    "src/security/ClientIp.php",
]


with tempfile.TemporaryDirectory(prefix="fas-paypal-readiness-") as temp:
    site = Path(temp)
    for name in FILES:
        target = site / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(ROOT / name, target)
    database = site / "database" / "fixture.sqlite"
    config = """<?php
return [
  'database' => ['path' => %s],
  'paypal' => ['client_id' => 'fixture', 'client_secret' => 'fixture',
               'mode' => 'sandbox', 'currency' => 'USD', 'webhook_id' => 'WH-FIXTURE'],
  'site' => ['timezone' => 'UTC'],
];
""" % json.dumps(database.as_posix())
    (site / "src/config/config.php").write_text(config, encoding="utf-8")
    with socket.socket() as listener:
        listener.bind(("127.0.0.1", 0))
        port = listener.getsockname()[1]
    with (site / "server.log").open("w") as log:
        server = subprocess.Popen(
            ["php", "-S", f"127.0.0.1:{port}", "-t", str(site)],
            cwd=site,
            env=os.environ.copy(),
            stdout=log,
            stderr=log,
            creationflags=getattr(subprocess, "CREATE_NO_WINDOW", 0),
        )
        try:
            for _ in range(50):
                try:
                    with socket.create_connection(("127.0.0.1", port), timeout=1):
                        break
                except OSError:
                    time.sleep(0.1)
            else:
                raise RuntimeError("Fixture server did not start")

            def post(event):
                request = urllib.request.Request(
                    f"http://127.0.0.1:{port}/api/paypal-webhook.php",
                    data=json.dumps(event).encode(),
                    headers={"Content-Type": "application/json"},
                )
                try:
                    response = urllib.request.urlopen(request, timeout=15)
                except urllib.error.HTTPError as error:
                    response = error
                with response:
                    return response.status, json.load(response)

            probe = {"id": "FAS-READINESS-0123456789abcdef", "event_type": "FAS.WEBHOOK.READINESS"}
            status, body = post(probe)
            assert status == 401 and body["error"] == "Webhook signature could not be verified"
            assert not database.exists(), "Readiness probe unexpectedly wrote to the monitor"

            status, _ = post({**probe, "resource": {}})
            assert status == 401
            with closing(sqlite3.connect(database)) as db:
                rows = db.execute("SELECT message, metadata FROM error_monitor_events ORDER BY id").fetchall()
            assert len(rows) == 1 and "signature verification failed" in rows[0][0]
            assert json.loads(rows[0][1])["event_type"] == "FAS.WEBHOOK.READINESS"

            status, _ = post({"id": "WH-EVENT", "event_type": "PAYMENT.CAPTURE.COMPLETED"})
            assert status == 401
            with closing(sqlite3.connect(database)) as db:
                rows = db.execute("SELECT metadata FROM error_monitor_events ORDER BY id").fetchall()
            assert len(rows) == 2 and json.loads(rows[1][0])["event_type"] == "PAYMENT.CAPTURE.COMPLETED"
        finally:
            server.terminate()
            server.wait(timeout=5)

print("PASS: readiness probe rejected without alarm; other unsigned events still alert. Isolated PHP HTTP fixture.")
