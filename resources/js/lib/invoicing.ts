export const countryCodes =
    'AD AE AF AG AI AL AM AO AQ AR AS AT AU AW AX AZ BA BB BD BE BF BG BH BI BJ BL BM BN BO BQ BR BS BT BV BW BY BZ CA CC CD CF CG CH CI CK CL CM CN CO CR CU CV CW CX CY CZ DE DJ DK DM DO DZ EC EE EG EH ER ES ET FI FJ FK FM FO FR GA GB GD GE GF GG GH GI GL GM GN GP GQ GR GS GT GU GW GY HK HM HN HR HT HU ID IE IL IM IN IO IQ IR IS IT JE JM JO JP KE KG KH KI KM KN KP KR KW KY KZ LA LB LC LI LK LR LS LT LU LV LY MA MC MD ME MF MG MH MK ML MM MN MO MP MQ MR MS MT MU MV MW MX MY MZ NA NC NE NF NG NI NL NO NP NR NU NZ OM PA PE PF PG PH PK PL PM PN PR PS PT PW PY QA RE RO RS RU RW SA SB SC SD SE SG SH SI SJ SK SL SM SN SO SR SS ST SV SX SY SZ TC TD TF TG TH TJ TK TL TM TN TO TR TT TV TW TZ UA UG UM US UY UZ VA VC VE VG VI VN VU WF WS YE YT ZA ZM ZW'.split(
        ' ',
    );
export type DocumentSummary = {
    public_uuid: string;
    number: string;
    kind: string;
    status: string;
    order_number: string;
    currency: string;
    grand_total: string;
    tax_total: string;
    issued_at: string;
    url: string;
    pdf_url: string;
};
export type InvoiceDocument = DocumentSummary & {
    subtotal: string;
    taxable_amount: string;
    inclusive: boolean | null;
    original: DocumentSummary | null;
    refund_reference: string | null;
    customer_snapshot: Record<string, string | null>;
    issuer_snapshot: Record<string, string | null>;
    provenance: string;
    payment_display: string;
    credits: DocumentSummary[];
    items: {
        product_name: string;
        offer_name: string;
        version: string;
        seller_name: string;
        quantity: number;
        unit_price: string;
        taxable_amount: string;
        tax_amount: string;
        grand_total: string;
        tax_type: string;
        tax_rate: string;
        jurisdiction: string;
        components: { type: string; rate: string; amount: string }[];
        service_code: string | null;
    }[];
};
