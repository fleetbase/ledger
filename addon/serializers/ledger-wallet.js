import LedgerSerializer from './ledger';

export default class LedgerWalletSerializer extends LedgerSerializer {
    /**
     * A wallet's balance only moves through its transactions, so saving an edit never sends
     * it back (the server ignores it too).
     */
    get attrs() {
        return {
            balance: { serialize: false },
            formatted_balance: { serialize: false },
        };
    }
}
