<?php

namespace Addr;

class AddressModes
{
    const INET4_ADDR       = 0b0001;
    const INET6_ADDR       = 0b0010;

    const PREFER_INET6     = 0b0100;

    const ANY_PREFER_INET4 = 0b0011;
    const ANY_PREFER_INET6 = 0b0111;

    const CNAME            = 0b1000;
}
