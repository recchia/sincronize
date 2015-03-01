sincronize
========================

Este proyecto tiene como objetivo la migración entre bases de dato  
de la aplicación winelo.

Fue desarrollado utilizando los siguientes componentes:


  * [**Symfony Console**][2]

  * [**Symfony Filesystem**][3]

  * [**Symfony Yaml**][4]

  * [**Doctrine DBAL**][5]
  
  * [**Monolog**][6]

1) Instalación y configuración
------------------------------

Para la intalación de este proyecto es necesario tener [Composer][1] instalado.

### Composer

El proyecto utiliza [Composer][1] para administrar sus dependencias, y será 
necesario tenerlo en tu equipo para poder instalar correctamente el software.

Si aun no tienes Composer, descargalo siguiendo las instrucciones en
http://getcomposer.org/ o ejecuta el siguiente comando:

    curl -s http://getcomposer.org/installer | php

Luego, usaremos el siguiente comando para descarga el código de la aplicación:

    git clone https://github.com/recchia/sincronize.git ruta/del/proyecto

Esto creara una copia del proyecto en el directorio `ruta/del/proyecto`. Si 
lo prefieres puedes hacer un fork para contribuir al proyecto.

### Instalación

El siguiente paso sera instalar las dependencias del proyecto mediante el 
comando:

    php composer.phar install

### Ejecución

La aplicación te guiara durante el proceso de migración, mediante 
instrucciones en pantalla. Para su ejecución debes estar en el 
directorio raíz de la aplicación.

Para ejecutar la aplicación deberas ejecutar el siguiente comando:

    php console.php wuelto:migrate [NombrePaís]

Donde nombre pais es un parametro obligatorio que indica cual país va a 
ser migrado. Si los archivos de configuración del pais fuente y la base 
de datos destino no existen la aplicación los creara por ti, solicitando 
los datos necesarios para generar el archivo.

[1]:  http://getcomposer.org/
[2]:  https://github.com/symfony/Console
[3]:  https://github.com/symfony/Filesystem
[4]:  https://github.com/symfony/Yaml
[5]:  https://github.com/doctrine/dbal.git
[6]:  https://github.com/Seldaek/monolog.git
