<?php

namespace AppBundle\Controller\ui;

//this is used for subscribing to before controller event to make sure the routes 
//validate access_token before allowing access to ui endpoints
interface TokenAuthenticatedController {
    // ...
}
