<?php

namespace Amp\Dns;

use LibDNS\Records\ResourceQTypes;
use LibDNS\Records\ResourceTypes;

class Record {
    const TYPE_A = ResourceTypes::A;
    const TYPE_AAAA = ResourceTypes::AAAA;
    const TYPE_AFSDB = ResourceTypes::AFSDB;
    // const TYPE_APL = ResourceTypes::APL;
    const TYPE_CAA = ResourceTypes::CAA;
    const TYPE_CERT = ResourceTypes::CERT;
    const TYPE_CNAME = ResourceTypes::CNAME;
    const TYPE_DHCID = ResourceTypes::DHCID;
    const TYPE_DLV = ResourceTypes::DLV;
    const TYPE_DNAME = ResourceTypes::DNAME;
    const TYPE_DNSKEY = ResourceTypes::DNSKEY;
    const TYPE_DS = ResourceTypes::DS;
    const TYPE_HINFO = ResourceTypes::HINFO;
    // const TYPE_HIP = ResourceTypes::HIP;
    // const TYPE_IPSECKEY = ResourceTypes::IPSECKEY;
    const TYPE_KEY = ResourceTypes::KEY;
    const TYPE_KX = ResourceTypes::KX;
    const TYPE_ISDN = ResourceTypes::ISDN;
    const TYPE_LOC = ResourceTypes::LOC;
    const TYPE_MB = ResourceTypes::MB;
    const TYPE_MD = ResourceTypes::MD;
    const TYPE_MF = ResourceTypes::MF;
    const TYPE_MG = ResourceTypes::MG;
    const TYPE_MINFO = ResourceTypes::MINFO;
    const TYPE_MR = ResourceTypes::MR;
    const TYPE_MX = ResourceTypes::MX;
    const TYPE_NAPTR = ResourceTypes::NAPTR;
    const TYPE_NS = ResourceTypes::NS;
    // const TYPE_NSEC = ResourceTypes::NSEC;
    // const TYPE_NSEC3 = ResourceTypes::NSEC3;
    // const TYPE_NSEC3PARAM = ResourceTypes::NSEC3PARAM;
    const TYPE_NULL = ResourceTypes::NULL;
    const TYPE_PTR = ResourceTypes::PTR;
    const TYPE_RP = ResourceTypes::RP;
    // const TYPE_RRSIG = ResourceTypes::RRSIG;
    const TYPE_RT = ResourceTypes::RT;
    const TYPE_SIG = ResourceTypes::SIG;
    const TYPE_SOA = ResourceTypes::SOA;
    const TYPE_SPF = ResourceTypes::SPF;
    const TYPE_SRV = ResourceTypes::SRV;
    const TYPE_TXT = ResourceTypes::TXT;
    const TYPE_WKS = ResourceTypes::WKS;
    const TYPE_X25 = ResourceTypes::X25;

    const TYPE_AXFR = ResourceQTypes::AXFR;
    const TYPE_MAILB = ResourceQTypes::MAILB;
    const TYPE_MAILA = ResourceQTypes::MAILA;
    const TYPE_ALL = ResourceQTypes::ALL;

    private $value;
    private $type;
    private $ttl;

    public function __construct(string $value, int $type, int $ttl = null) {
        $this->value = $value;
        $this->type = $type;
        $this->ttl = $ttl;
    }

    public function getValue(): string {
        return $this->value;
    }

    public function getType(): int {
        return $this->type;
    }

    public function getTtl() {
        return $this->ttl;
    }
}
