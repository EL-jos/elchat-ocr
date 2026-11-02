# TLS des connexions MySQL DevPilot

DevPilot résout l’hôte configuré, vérifie toutes les adresses obtenues avec la politique réseau, puis épingle la connexion MySQL à une adresse IP dans le DSN. Il exige un bundle d’autorités lisible et garde `PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT` à `true`.

Le certificat présenté par MySQL doit donc être valide pour l’adresse IP effectivement utilisée, avec un SAN de type IP correspondant, en plus d’être signé par une autorité de confiance. Un certificat qui ne contient que le nom DNS échoue volontairement. Ne désactivez pas la validation et ne remplacez pas l’IP du DSN par le nom DNS après une simple vérification préalable : le second cas peut résoudre vers une autre adresse et réintroduire une fenêtre de rebinding.

Pour accepter à l’avenir des certificats limités à un nom DNS, il faudra un client qui permet de garder le socket TCP épinglé à l’IP validée tout en configurant séparément le nom TLS attendu. Le chemin PDO/mysqlnd utilisé ici n’expose pas cette séparation.

Références : [documentation PDO MySQL](https://www.php.net/manual/en/class.pdo-mysql.php), [code de pdo_mysql](https://github.com/php/php-src/blob/master/ext/pdo_mysql/mysql_driver.c), [code TLS de mysqlnd](https://github.com/php/php-src/blob/master/ext/mysqlnd/mysqlnd_vio.c).
