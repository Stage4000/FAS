"""Exact reviewed PR183 copy restoration for historical test identities only.
Runtime fixtures retain current source. Unknown bytes are never normalized.
"""
import hashlib

HASHES = {'product.php': ('b17a178e4c8e32ce2c37aae7cb9ddf1ee34230ad6012e1ec2b3abeee90b45336', 'cbfbccac53984a56b6334f82dd75521fb26f4c402a3ea76331c42bfb9f5fb752'), 'cart.php': ('25a6ab74534ce92ddceeb49343024c845f4f940c9322a9be46a00f51aea888fd', 'e3c6f6ee0b91ed5c849ef38bb314abbcdb69cbfef5bcb70bdb58c124495f3d60')}
COPIES = {'product.php': [('Free shipping to continental US', 'Free shipping · Lower 48 + DC'), ('This item qualifies for free shipping to continental US addresses. The $0 shipping option appears during checkout after an eligible address is entered.', 'This item qualifies for free shipping to eligible addresses in the 48 contiguous states and Washington, DC. Enter your address at checkout to see the $0 option.'), ('Enter city, state, and ZIP to check estimated shipping or continental US free-shipping eligibility.', 'Enter your city, state, and ZIP to estimate shipping and check whether your address qualifies for free shipping.')], 'cart.php': [('Free shipping eligible for continental US addresses', 'Free shipping eligible in the 48 contiguous states and Washington, DC')]}

def restore(path, data):
    pins = HASHES.get(path)
    if pins is None or hashlib.sha256(data).hexdigest() != pins[0]:
        return data
    restored = data
    for old, new in COPIES[path]:
        old, new = old.encode(), new.encode()
        if restored.count(new) != 1:
            raise ValueError('Exact shipping copy anchor required: ' + path)
        restored = restored.replace(new, old, 1)
    if hashlib.sha256(restored).hexdigest() != pins[1]:
        raise ValueError('Shipping copy restoration must equal exact preimage: ' + path)
    return restored
