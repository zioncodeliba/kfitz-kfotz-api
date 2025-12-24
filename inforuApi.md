Send Existing Campaign
curl --location 'https://capi.inforu.co.il/api/Umail/Campaign/Send/' \
--header 'Content-Type: application/json' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--data-raw '{
    "Data": {
        "CampaignId": "57934763",
        "Duplicate": 0,
        "IncludeContacts": [
            {
                "FirstName": "string",
                "Email": "example1@example.com"
            }
        ]
    }
}'

Send New Campaign
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Message/Send' \
--header 'Content-Type: application/json; charset=utf-8' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--data-raw '{
    "Data": {
        "CampaignName": "My First Email",
        "CampaignRefId": "213456789",
        "FromAddress": "ariel.l@inforu.co.il",
        "ReplyAddress": "ariel@abc.co.il",
        "FromName": "Abc Company",
        "Subject": "Summer Sale",
        "PreHeader": "AAAA DDDDD",
        "Body": "test...",
        "IncludeContacts": [
            {
                "Email": "ohad@inforu.co.il"
            }
        ]
    }
}'

Campaign
Get Campaign List
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Campaign/List/' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json; charset=utf-8' \
--data '{
    "Data": {
        "CampaignIds": [
            "30000810"
        ],
        "StartDate": "2022-05-31",
        "EndDate": "2023-05-31"
    }
}'

Get Campaign
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Campaign/Get/' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json; charset=utf-8' \
--data '{
    "Data": {
        "CampaignId": 
            "30000809"
    }
}'

Update Campaign
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Campaign/Update' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json; charset=utf-8' \
--data '{
    "Data": {
        "DuplicateFromCampaignId": "30000810",

        "StringReplace": [
            {
                "Field": "Subject",
                "Search": "param1",
                "Replace": "param2"
            }
        ]
    }
}'

Utilities
Get job status
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Campaign/Job' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json; charset=utf-8' \
--data '{
    "Data": {
        "JobToken": "715fzq90yzsi"
    }
}'

Get Mail Notification
curl --location 'https://capi.inforu.co.il/api/v2/Umail/GetMailNotification' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json; charset=utf-8' \
--data '{
    "Data": {
        "BatchSize": "200"
    }
}'

Notification On Unsubscribe
curl --location 'http://www.clienturl.co.il/demo.aspx' \
--data-raw '<InfoMailClient>
<ContactsRemoved>
    <contact email="email@inforu.co.il" UserId="1" Username="test" CustomerId="1" ProjectId="1"/>
</ContactsRemoved>
</InfoMailClient>'

Stop Future Campaign
curl --location 'https://capi.inforu.co.il/api/v2/Umail/Campaign/Stop' \
--header 'Content-Type: application/json' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA=='
--data '{
    "Data": {
        "CampaignId": "91619263"
    }
}'

SendSms
curl --location 'https://capi.inforu.co.il/api/v2/SMS/SendSms' \
--header 'Authorization: Basic Z2FiZG9yOjE1MWU5NmNjLTE3YzUtNDRiZS05ZTgzLTk3ZWM3MDk3OTJlNA==' \
--header 'Content-Type: application/json' \
--data '{
    "Data": {
        "Message": "Hello world",
        "Recipients": [
            {
                "Phone": "0504071205"
            }
        ],
         "Settings": {
            "Sender": "MyBrand"
         }
    }
}'

