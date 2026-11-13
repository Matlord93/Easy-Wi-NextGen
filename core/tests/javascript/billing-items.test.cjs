const {test}=require('node:test');
const assert=require('node:assert/strict');
const {money,quantity,calculate}=require('../../public/js/billing-items.js');

test('mixed tax and fractional quantities match the server cent contract',()=>{
 const result=calculate([{quantity:'2',unit_price:'119,00',tax_rate:'19'},{quantity:'1.5',unit_price:'10.70',tax_rate:'7'}],'standard');
 assert.deepEqual(result,{net:21500,tax:3905,gross:25405,items:[23800,1605]});
});
test('half cents round up per line and free items remain zero',()=>{
 const row={quantity:'0.5',unit_price:'0.01',tax_rate:'19'};
 assert.deepEqual(calculate([row,row,{...row,unit_price:'0'}],'standard'),{net:2,tax:0,gross:2,items:[1,1,0]});
});
test('VAT is rounded per rate for many small lines without changing gross prices',()=>{
 const result=calculate(Array(100).fill({quantity:'1',unit_price:'0.03',tax_rate:'19'}),'standard');
 assert.equal(result.net,252);assert.equal(result.tax,48);assert.equal(result.gross,300);assert.deepEqual([...new Set(result.items)],[3]);
});
test('nonstandard tax modes use zero tax while retaining gross',()=>{
 for(const mode of ['exempt','reverse_charge','small_business']) assert.deepEqual(calculate([{quantity:'1',unit_price:'119',tax_rate:'19'}],mode),{net:11900,tax:0,gross:11900,items:[11900]});
});
test('amount and quantity parsing is bounded and exact',()=>{
 assert.equal(money('21474836.47'),2147483647n);assert.equal(quantity('999999,999'),999999999n);assert.equal(quantity('0.001'),1n);
 for(const raw of ['1e3','-1','1.234','21474836.48','1,000.00',null,{}])assert.equal(money(raw),null);
 for(const raw of ['0','-1','1e2','1.0001','1000000',null,{}])assert.equal(quantity(raw),null);
});
test('invalid or overflowing rows show no manufactured total',()=>{
 const row={quantity:'1',unit_price:'119',tax_rate:'19'};
 for(const rows of [[],Array(101).fill(row),[{...row,tax_rate:'101'}],[{...row,quantity:'2',unit_price:'21474836.47'}],[{...row,quantity:''}]])assert.equal(calculate(rows,'standard'),null);
 assert.equal(calculate([row],'unknown'),null);
});
