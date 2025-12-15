אז קודם כל יש את הטוקן
שהוא יהיה בערך גלובלי
--header 'Authorization: Bearer {{token}}'
לבנתיים שיהיה ריק ואני יוסיף אותו לתוכנה


יצירת משלוח:
CREATE A SHIPMENT IN RUN SYSTEM
This API is based on a GET request to URL with a string of arguments. It works as a web service and we refer to it as WS Simple.

Request:
https://chita-il.com/RunCom.Server/Request.aspx?APPNAME=run&PRGNAME=ship_create_anonymou
s&ARGUMENTS=-N<P1>,-A<P2>,-N<P3>,-N<P4>,-A<P5>,-A<P6>,-N<P7>,-N<P8>,-N<P9>,-N<P10>,-A<P11
>,-A<P12>,-A<P13>,-A<P14>,-A<P15>,-A<P16>,-A<P17>,-A<P18>,-A<P19>,-A<P20>,-A<P21>,-A<P22>,-A<P2
3>,-A<P24>,-A<P25>,-A<P26>,-A<P27>,-A<P28>,-N<P29>,-N<P30>,-N<P31>,-A<P32>,-A<P33>,-N<P34>,
-N<P35>,-A<P36>,-A<P37>,-A<P38>,-N<P39>,-N<P40>,-N<P41>,-N<P42>

Notes:
1. Tag symbols <> need to be removed.
2. There is a comma after each argument.
3. There is a -A or a -N before each argument except HOST. These are mandatory symbols
which indicate field type (numeric or string) to the server, and should not be removed.
4. If the field is empty, it still has to be sent in the request.
5. There cannot be commas and ‘&’ characters in the text of a parameter itself. Please
perform validation of parameter fields text before sending.

P1
Numeric 8
Customer number (get it from the shipping company)
P2
String 6
for deliveries – מסירה for returns - איסוף
Please use the word in Hebrew exactly as spelled above.
P3
Numeric 5
Shipment type - get the code from the shipping company
P4
Numeric 5
Shipment stage
Consult with the shipping company which code to send (if any)
P5
String 10
Your company name
P6
String 10
Please leave blank
P7
Numeric 5
Shipped cargo type - get the code from the shipping company
P8
Numeric 5
Returned cargo type (relevant for returns only) - get the code from the shipping company
P9
Numeric 3
Number of returned packages (relevant for returns only)
P10
Numeric 3
Please leave blank
P11
String 20
Consignee's name
P12
String 10
City/settlement code - optional
If you are sending city codes, please use the gov.il database
 P13
String 30
City/settlement name
P14
String 10
Street code
P15
String 30
Street name
This field can contain street name together with building number, in this case P16 can be left blank.
P16
String 5
Building No.
P17
String 1
Entrance No.
P18
String 2
Floor No.
P19
String 4
Apartment No.
P20
String 20
Primary phone number (cellular)
P21
String 20
Additional phone number
P22
String 200
Your reference number for the shipment
You can also send several reference numbers separated by ":"
Please consult with the shipping company which parameter is preferable to use in your case - P22 or P26.
P23
Numeric 6
Number of packages to be delivered
This field is mandatory if there is more than one package in the shipment.
P24
String 70
Address remarks
P25
String 80
Additional shipment remarks (if any)
P26
String 50
Second reference number for the shipment
Please consult with the shipping company which parameter is preferable to use in your case - P22 or P26.
P27
String DD/MM/YYYY
Date
If you want the shipment to be picked up on a specific date which is more than a day away from the date of the request, you can specify a date in this field.
P28
String HH:MM
Time
If you want the shipment to be picked up at a specific hour which is more than a day away from the date of the request, you can specify time in this field.
P29
Numeric 12
Please leave blank
P30
Numeric 3
If the courier needs to collect payment from the consignee, please specify the payment type code in this field - get it from the shipping company
P31
Numeric 8.2
The sum to be collected from the consignee
P32
String DD/MM/YYYY
The date of payment collection from the consignee
P33
String 500
Notes for payment collection
P34
Numeric
Source pickup point – relevant for returns only
P35
Numeric
Destination pickup point
Relevant only for shipments to pickup points
Please fill in if your customer has chosen a pickup point on your website.
If you fill in this field, please leave P37 blank.
P36
String
Response type - TXT (default) or XML (recommended)
P37
String 1
Run system can choose a pickup point for a shipment automatically, based on a consignee’s address (the closest working point will be assigned).
N = Do not assign (default)
Y = Assign any type (store or locker) L = Assign a locker
S = Assign a store
P38
String
Please leave blank
P39
Numeric
Please leave blank
P40
String 100
Consignee's email
P41
String DD/MM/YYYY
Parcel preparation date
This field is used in case your parcels are assembled at the shipping company warehouse.
P42
String HH:MM
Parcel preparation time
This field is used in case your parcels are assembled at the shipping company warehouse.