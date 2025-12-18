generateAccessToken:
curl --location 'https://ypay.co.il/api/v1/accessToken' \
--header 'Content-Type: application/json' \
--header 'Cookie: PHPSESSID=l68nnp6a68t0g8lfe0l4t3ktn2' \
--data '{
"client_id": "NDAxNDM=",
"client_secret": "daaf195d82a3c448d531e20e44db93b67f4dfb3f"
}'

והתשובה מהשרת:
{"access_token":"5d315e9277b0d77d63ed36b3851d5f621f51e841dac9e4ca86d9f9ed80a63cc1","lifetime":3600,"token_type":"bearer","scope":null}

Document Generator:
curl --location 'https://ypay.co.il/api/v1/document' \
--header 'Content-Type: application/json' \
--header 'Authorization: Bearer 6d77d192f4da0b5b9143039061d648812eed3f4ca78d7ff5826db6e76eab2ef2' \
--header 'Cookie: PHPSESSID=l68nnp6a68t0g8lfe0l4t3ktn2' \
--data-raw '{
  "docType": 106,
  "mail": true,
  "details": "test test test test",
  "lang": "he",
  "contact": {
    "email": "zioncodeliba@gmail.com",
    "businessID": "040888888",
    "name": "test test",
    "phone": "02-5866119",
    "mobile": "0504071205",
    "zipcode": "5260170",
    "website": "wwww.mywebsite.co.il",
    "address": "Hgavish 2, Rannana",
    "comments": "Just a comment"
  },
  "items": [
    {
      "price": 1,
      "quantity": 1.0,
      "vatIncluded": true,
      "name": "test monthly payment",
      "description": "test test test test test test test"
    }
  ],
  "methods":[
    {
      "type": 1,
      "total": 1.0,
      "date": "2025-12-18"
    }
  ]
}'

והתשובה מהשרת:
{"url":"https://ypay.co.il/fsrv/business/40143/customers/123/official/ca801505b3a026673cbbc0ada180e4d3a781ec31.pdf","serialNumber":1006247,"responseCode":1}