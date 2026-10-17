#!/usr/bin/env python3
"""Regression: a real Asaas webhook envelope containing '&' must get HTTP 200."""
import json
import urllib.error
import urllib.request

ENDPOINT = "http://127.0.0.1:8080/webhooks/asaas"
TOKEN = "ci_webhook_auth_token_only_for_tests_2026"
EVENT = {
    "id": "evt_05b708f961d739ea7eba7e4db318f621&368604921",
    "event": "PAYMENT_CREATED",
    "dateCreated": "2026-10-17 10:16:39",
    "account": {"id": "ci-account", "ownerId": None},
    "payment": {"object": "payment", "id": "pay_7zutocfjc62p8jl", "value": 5.0},
}

def send(obj, token, expected):
    body = json.dumps(obj).encode("utf-8") if isinstance(obj, dict) else obj
    headers = {"Content-Type": "application/json"}
    if token is not None:
        headers["asaas-access-token"] = token
    req = urllib.request.Request(ENDPOINT, data=body, headers=headers, method="POST")
    try:
        with urllib.request.urlopen(req, timeout=15) as resp:
            code = resp.status
    except urllib.error.HTTPError as error:
        code = error.code
    assert code == expected, f"Webhook returned {code}, expected {expected}"

send(EVENT, None, 401)
send(EVENT, "wrong-token", 401)
send(EVENT, TOKEN, 200)
send(EVENT, TOKEN, 200)  # Asaas at-least-once redelivery
send(b"{bad JSON", TOKEN, 400)
send(dict(EVENT, id="evt_bad<script>&1"), TOKEN, 400)
print("ASAAS_WEBHOOK_HTTP_REAL_EVENT_OK")
