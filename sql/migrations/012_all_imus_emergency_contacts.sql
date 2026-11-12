/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.11.18-MariaDB, for debian-linux-gnu (x86_64)
--
-- Host: localhost    Database: sentri
-- ------------------------------------------------------
-- Server version	10.11.18-MariaDB-0+deb12u1

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Dumping data for table `emergency_contacts`
--

LOCK TABLES `emergency_contacts` WRITE;
/*!40000 ALTER TABLE `emergency_contacts` DISABLE KEYS */;
INSERT INTO `emergency_contacts` VALUES
(1,'Imus City Disaster Risk Reduction & Management Office (CDRRMO)','lgu',NULL,'Imus','Cavite','(046) 471-0100','drrmo@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(2,'Imus City Hall Action Center & Hotline','lgu',NULL,'Imus','Cavite','(046) 471-0200','actioncenter@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(3,'Imus City Health Office (CHO)','lgu',NULL,'Imus','Cavite','(046) 471-0101','health@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(4,'Imus City Social Welfare & Development (CSWD)','lgu',NULL,'Imus','Cavite','(046) 471-0102','cswd@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(5,'Imus City Public Information Office (PIO)','lgu',NULL,'Imus','Cavite','(046) 471-0104','pio@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(6,'PNP Imus City Police Station','police',NULL,'Imus','Cavite','(046) 471-0800','pnp.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(7,'BFP Imus City Fire Station','fire',NULL,'Imus','Cavite','(046) 471-1166','bfp.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(8,'Ospital ng Imus (Emergency Department)','hospital',NULL,'Imus','Cavite','(046) 471-9999','hospital@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(9,'Imus Traffic Management Office (TMO)','traffic',NULL,'Imus','Cavite','(046) 471-0200','traffic@imus.gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(10,'Philippine Red Cross - Cavite Chapter (Imus)','first_responder',NULL,'Imus','Cavite','(046) 431-0562','cavite@redcross.org.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(11,'National Emergency Hotline','first_responder',NULL,'Imus','Cavite','911','emergency@gov.ph',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(12,'Barangay Alapan I-A Emergency Hotline','barangay','Alapan I-A','Imus','Cavite','(046) 471-1001','brgy.alapania.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(13,'Barangay Alapan I-B Emergency Hotline','barangay','Alapan I-B','Imus','Cavite','(046) 471-1002','brgy.alapanib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(14,'Barangay Alapan I-C Emergency Hotline','barangay','Alapan I-C','Imus','Cavite','(046) 471-1003','brgy.alapanic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(15,'Barangay Alapan II-A Emergency Hotline','barangay','Alapan II-A','Imus','Cavite','(046) 471-1004','brgy.alapaniia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(16,'Barangay Alapan II-B Emergency Hotline','barangay','Alapan II-B','Imus','Cavite','(046) 471-1005','brgy.alapaniib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(17,'Barangay Anabu I-A Emergency Hotline','barangay','Anabu I-A','Imus','Cavite','(046) 471-1006','brgy.anabuia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(18,'Barangay Anabu I-B Emergency Hotline','barangay','Anabu I-B','Imus','Cavite','(046) 471-1007','brgy.anabuib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(19,'Barangay Anabu I-C Emergency Hotline','barangay','Anabu I-C','Imus','Cavite','(046) 471-1008','brgy.anabuic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(20,'Barangay Anabu I-D Emergency Hotline','barangay','Anabu I-D','Imus','Cavite','(046) 471-1009','brgy.anabuid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(21,'Barangay Anabu I-E Emergency Hotline','barangay','Anabu I-E','Imus','Cavite','(046) 471-1010','brgy.anabuie.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(22,'Barangay Anabu I-F Emergency Hotline','barangay','Anabu I-F','Imus','Cavite','(046) 471-1011','brgy.anabuif.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(23,'Barangay Anabu I-G Emergency Hotline','barangay','Anabu I-G','Imus','Cavite','(046) 471-1012','brgy.anabuig.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(24,'Barangay Anabu II-A Emergency Hotline','barangay','Anabu II-A','Imus','Cavite','(046) 471-1013','brgy.anabuiia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(25,'Barangay Anabu II-B Emergency Hotline','barangay','Anabu II-B','Imus','Cavite','(046) 471-1014','brgy.anabuiib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(26,'Barangay Anabu II-C Emergency Hotline','barangay','Anabu II-C','Imus','Cavite','(046) 471-1015','brgy.anabuiic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(27,'Barangay Anabu II-D Emergency Hotline','barangay','Anabu II-D','Imus','Cavite','(046) 471-1016','brgy.anabuiid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(28,'Barangay Anabu II-E Emergency Hotline','barangay','Anabu II-E','Imus','Cavite','(046) 471-1017','brgy.anabuiie.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(29,'Barangay Anabu II-F Emergency Hotline','barangay','Anabu II-F','Imus','Cavite','(046) 471-1018','brgy.anabuiif.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(30,'Barangay Bagong Silang Emergency Hotline','barangay','Bagong Silang','Imus','Cavite','(046) 471-1019','brgy.bagongsilang.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(31,'Barangay Bayan Luma I Emergency Hotline','barangay','Bayan Luma I','Imus','Cavite','(046) 471-1020','brgy.bayanlumai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(32,'Barangay Bayan Luma II Emergency Hotline','barangay','Bayan Luma II','Imus','Cavite','(046) 471-1021','brgy.bayanlumaii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(33,'Barangay Bayan Luma III Emergency Hotline','barangay','Bayan Luma III','Imus','Cavite','(046) 471-1022','brgy.bayanlumaiii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(34,'Barangay Bayan Luma IV Emergency Hotline','barangay','Bayan Luma IV','Imus','Cavite','(046) 471-1023','brgy.bayanlumaiv.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(35,'Barangay Bayan Luma V Emergency Hotline','barangay','Bayan Luma V','Imus','Cavite','(046) 471-1024','brgy.bayanlumav.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(36,'Barangay Bayan Luma VI Emergency Hotline','barangay','Bayan Luma VI','Imus','Cavite','(046) 471-1025','brgy.bayanlumavi.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(37,'Barangay Bayan Luma VII Emergency Hotline','barangay','Bayan Luma VII','Imus','Cavite','(046) 471-1026','brgy.bayanlumavii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(38,'Barangay Bayan Luma VIII Emergency Hotline','barangay','Bayan Luma VIII','Imus','Cavite','(046) 471-1027','brgy.bayanlumaviii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(39,'Barangay Bayan Luma IX Emergency Hotline','barangay','Bayan Luma IX','Imus','Cavite','(046) 471-1028','brgy.bayanlumaix.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(40,'Barangay Bucandala I Emergency Hotline','barangay','Bucandala I','Imus','Cavite','(046) 471-1029','brgy.bucandalai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(41,'Barangay Bucandala II Emergency Hotline','barangay','Bucandala II','Imus','Cavite','(046) 471-1030','brgy.bucandalaii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(42,'Barangay Bucandala III Emergency Hotline','barangay','Bucandala III','Imus','Cavite','(046) 471-1031','brgy.bucandalaiii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(43,'Barangay Bucandala IV Emergency Hotline','barangay','Bucandala IV','Imus','Cavite','(046) 471-1032','brgy.bucandalaiv.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(44,'Barangay Bucandala V Emergency Hotline','barangay','Bucandala V','Imus','Cavite','(046) 471-1033','brgy.bucandalav.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(45,'Barangay Buhay na Tubig Emergency Hotline','barangay','Buhay na Tubig','Imus','Cavite','(046) 471-1034','brgy.buhaynatubig.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(46,'Barangay Carsadang Bago I Emergency Hotline','barangay','Carsadang Bago I','Imus','Cavite','(046) 471-1035','brgy.carsadangbagoi.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(47,'Barangay Carsadang Bago II Emergency Hotline','barangay','Carsadang Bago II','Imus','Cavite','(046) 471-1036','brgy.carsadangbagoii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(48,'Barangay Magdalo Emergency Hotline','barangay','Magdalo','Imus','Cavite','(046) 471-1037','brgy.magdalo.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(49,'Barangay Maharlika Emergency Hotline','barangay','Maharlika','Imus','Cavite','(046) 471-1038','brgy.maharlika.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(50,'Barangay Malagasang I-A Emergency Hotline','barangay','Malagasang I-A','Imus','Cavite','(046) 471-1039','brgy.malagasangia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(51,'Barangay Malagasang I-B Emergency Hotline','barangay','Malagasang I-B','Imus','Cavite','(046) 471-1040','brgy.malagasangib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(52,'Barangay Malagasang I-C Emergency Hotline','barangay','Malagasang I-C','Imus','Cavite','(046) 471-1041','brgy.malagasangic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(53,'Barangay Malagasang I-D Emergency Hotline','barangay','Malagasang I-D','Imus','Cavite','(046) 471-1042','brgy.malagasangid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(54,'Barangay Malagasang I-E Emergency Hotline','barangay','Malagasang I-E','Imus','Cavite','(046) 471-1043','brgy.malagasangie.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(55,'Barangay Malagasang I-F Emergency Hotline','barangay','Malagasang I-F','Imus','Cavite','(046) 471-1044','brgy.malagasangif.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(56,'Barangay Malagasang I-G Emergency Hotline','barangay','Malagasang I-G','Imus','Cavite','(046) 471-1045','brgy.malagasangig.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(57,'Barangay Malagasang II-A Emergency Hotline','barangay','Malagasang II-A','Imus','Cavite','(046) 471-1046','brgy.malagasangiia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(58,'Barangay Malagasang II-B Emergency Hotline','barangay','Malagasang II-B','Imus','Cavite','(046) 471-1047','brgy.malagasangiib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(59,'Barangay Malagasang II-C Emergency Hotline','barangay','Malagasang II-C','Imus','Cavite','(046) 471-1048','brgy.malagasangiic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(60,'Barangay Malagasang II-D Emergency Hotline','barangay','Malagasang II-D','Imus','Cavite','(046) 471-1049','brgy.malagasangiid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(61,'Barangay Malagasang II-E Emergency Hotline','barangay','Malagasang II-E','Imus','Cavite','(046) 471-1050','brgy.malagasangiie.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(62,'Barangay Malagasang II-F Emergency Hotline','barangay','Malagasang II-F','Imus','Cavite','(046) 471-1051','brgy.malagasangiif.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(63,'Barangay Malagasang II-G Emergency Hotline','barangay','Malagasang II-G','Imus','Cavite','(046) 471-1052','brgy.malagasangiig.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(64,'Barangay Mariano Espeleta I Emergency Hotline','barangay','Mariano Espeleta I','Imus','Cavite','(046) 471-1053','brgy.marianoespeletai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(65,'Barangay Medicion I-A Emergency Hotline','barangay','Medicion I-A','Imus','Cavite','(046) 471-1054','brgy.medicionia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(66,'Barangay Medicion I-B Emergency Hotline','barangay','Medicion I-B','Imus','Cavite','(046) 471-1055','brgy.medicionib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(67,'Barangay Medicion I-C Emergency Hotline','barangay','Medicion I-C','Imus','Cavite','(046) 471-1056','brgy.medicionic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(68,'Barangay Medicion I-D Emergency Hotline','barangay','Medicion I-D','Imus','Cavite','(046) 471-1057','brgy.medicionid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(69,'Barangay Medicion II-A Emergency Hotline','barangay','Medicion II-A','Imus','Cavite','(046) 471-1058','brgy.medicioniia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(70,'Barangay Medicion II-B Emergency Hotline','barangay','Medicion II-B','Imus','Cavite','(046) 471-1059','brgy.medicioniib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(71,'Barangay Medicion II-C Emergency Hotline','barangay','Medicion II-C','Imus','Cavite','(046) 471-1060','brgy.medicioniic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(72,'Barangay Medicion II-D Emergency Hotline','barangay','Medicion II-D','Imus','Cavite','(046) 471-1061','brgy.medicioniid.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(73,'Barangay Medicion II-E Emergency Hotline','barangay','Medicion II-E','Imus','Cavite','(046) 471-1062','brgy.medicioniie.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(74,'Barangay Medicion II-F Emergency Hotline','barangay','Medicion II-F','Imus','Cavite','(046) 471-1063','brgy.medicioniif.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(75,'Barangay Pag-asa I Emergency Hotline','barangay','Pag-asa I','Imus','Cavite','(046) 471-1064','brgy.pagasai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(76,'Barangay Pag-asa II Emergency Hotline','barangay','Pag-asa II','Imus','Cavite','(046) 471-1065','brgy.pagasaii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(77,'Barangay Pag-asa III Emergency Hotline','barangay','Pag-asa III','Imus','Cavite','(046) 471-1066','brgy.pagasaiii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(78,'Barangay Palico I Emergency Hotline','barangay','Palico I','Imus','Cavite','(046) 471-1067','brgy.palicoi.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(79,'Barangay Palico II Emergency Hotline','barangay','Palico II','Imus','Cavite','(046) 471-1068','brgy.palicoii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(80,'Barangay Palico III Emergency Hotline','barangay','Palico III','Imus','Cavite','(046) 471-1069','brgy.palicoiii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(81,'Barangay Palico IV Emergency Hotline','barangay','Palico IV','Imus','Cavite','(046) 471-1070','brgy.palicoiv.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(82,'Barangay Pasong Buaya I Emergency Hotline','barangay','Pasong Buaya I','Imus','Cavite','(046) 471-1071','brgy.pasongbuayai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(83,'Barangay Pasong Buaya II Emergency Hotline','barangay','Pasong Buaya II','Imus','Cavite','(046) 471-1072','brgy.pasongbuayaii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(84,'Barangay Pinagbuklod Emergency Hotline','barangay','Pinagbuklod','Imus','Cavite','(046) 471-1073','brgy.pinagbuklod.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(85,'Barangay Poblacion I-A Emergency Hotline','barangay','Poblacion I-A','Imus','Cavite','(046) 471-1074','brgy.poblacionia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(86,'Barangay Poblacion I-B Emergency Hotline','barangay','Poblacion I-B','Imus','Cavite','(046) 471-1075','brgy.poblacionib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(87,'Barangay Poblacion I-C Emergency Hotline','barangay','Poblacion I-C','Imus','Cavite','(046) 471-1076','brgy.poblacionic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(88,'Barangay Poblacion II-A Emergency Hotline','barangay','Poblacion II-A','Imus','Cavite','(046) 471-1077','brgy.poblacioniia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(89,'Barangay Poblacion II-B Emergency Hotline','barangay','Poblacion II-B','Imus','Cavite','(046) 471-1078','brgy.poblacioniib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(90,'Barangay Poblacion III-A Emergency Hotline','barangay','Poblacion III-A','Imus','Cavite','(046) 471-1079','brgy.poblacioniiia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(91,'Barangay Poblacion III-B Emergency Hotline','barangay','Poblacion III-B','Imus','Cavite','(046) 471-1080','brgy.poblacioniiib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(92,'Barangay Poblacion IV-A Emergency Hotline','barangay','Poblacion IV-A','Imus','Cavite','(046) 471-1081','brgy.poblacioniva.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(93,'Barangay Poblacion IV-B Emergency Hotline','barangay','Poblacion IV-B','Imus','Cavite','(046) 471-1082','brgy.poblacionivb.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(94,'Barangay Poblacion IV-C Emergency Hotline','barangay','Poblacion IV-C','Imus','Cavite','(046) 471-1083','brgy.poblacionivc.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(95,'Barangay Poblacion IV-D Emergency Hotline','barangay','Poblacion IV-D','Imus','Cavite','(046) 471-1084','brgy.poblacionivd.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(96,'Barangay Tanzang Luma I Emergency Hotline','barangay','Tanzang Luma I','Imus','Cavite','(046) 471-1085','brgy.tanzanglumai.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(97,'Barangay Tanzang Luma II Emergency Hotline','barangay','Tanzang Luma II','Imus','Cavite','(046) 471-1086','brgy.tanzanglumaii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(98,'Barangay Tanzang Luma III Emergency Hotline','barangay','Tanzang Luma III','Imus','Cavite','(046) 471-1087','brgy.tanzanglumaiii.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(99,'Barangay Tanzang Luma IV Emergency Hotline','barangay','Tanzang Luma IV','Imus','Cavite','(046) 471-1088','brgy.tanzanglumaiv.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(100,'Barangay Tanzang Luma V Emergency Hotline','barangay','Tanzang Luma V','Imus','Cavite','(046) 471-1089','brgy.tanzanglumav.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(101,'Barangay Tanzang Luma VI Emergency Hotline','barangay','Tanzang Luma VI','Imus','Cavite','(046) 471-1090','brgy.tanzanglumavi.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(102,'Barangay Toclong I-A Emergency Hotline','barangay','Toclong I-A','Imus','Cavite','(046) 471-1091','brgy.toclongia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(103,'Barangay Toclong I-B Emergency Hotline','barangay','Toclong I-B','Imus','Cavite','(046) 471-1092','brgy.toclongib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(104,'Barangay Toclong I-C Emergency Hotline','barangay','Toclong I-C','Imus','Cavite','(046) 471-1093','brgy.toclongic.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(105,'Barangay Toclong II-A Emergency Hotline','barangay','Toclong II-A','Imus','Cavite','(046) 471-1094','brgy.toclongiia.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15'),
(106,'Barangay Toclong II-B Emergency Hotline','barangay','Toclong II-B','Imus','Cavite','(046) 471-1095','brgy.toclongiib.imus@gmail.com',1,'2026-11-12 10:31:15','2026-11-12 10:31:15');
/*!40000 ALTER TABLE `emergency_contacts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-12  5:31:27
