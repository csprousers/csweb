<?php

namespace AppBundle\Controller\api;

//this is used for subscribing to before controller event to make sure the routes 
//validate token before allowing access to api endpoints
interface ApiTokenAuthenticatedController {
    // ...
}
