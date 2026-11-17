const http = require('http');
http.createServer(async (req,res) => {
  res.setHeader('Content-Type','application/json');
  if (req.method !== 'POST' || req.url !== '/orders/get') {res.statusCode=501;res.end(JSON.stringify({error:'unmodeled'}));return;}
  let body='';for await (const chunk of req) body+=chunk;
  let input;try {input=typeof req.body === 'object' ? req.body : JSON.parse(body);} catch {res.statusCode=400;res.end('{}');return;}
  if (input.params?.ordersStatuses?.[0] !== 'packed_ready' || !Number.isInteger(input.params?.resultsPage)) {res.statusCode=400;res.end(JSON.stringify({error:'invalid request contract'}));return;}
  if (input.params.resultsPage > 0) {res.end(JSON.stringify({Results:[]}));return;}
  res.end(JSON.stringify({Results:[{orderSerialNumber:'SIM-123',clientResult:{clientBillingAddress:{clientCountryName:'Polska'}},orderDetails:{productsResults:[{productId:100,productName:'Testowy komputer',productQuantity:1}]}}]}));
}).listen(process.env.PORT);
