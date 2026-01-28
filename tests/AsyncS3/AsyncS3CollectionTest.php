<?php

declare(strict_types=1);

namespace Tests\AsyncS3;

use AsyncAws\Core\Response;
use AsyncAws\Core\Test\Http\SimpleMockedResponse;
use AsyncAws\S3\Result\GetObjectAclOutput;
use AsyncAws\S3\ValueObject\AwsObject;
use Kcs\Filesystem\AsyncS3\AsyncS3Collection;
use Kcs\Filesystem\AsyncS3\S3FileStat;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

class AsyncS3CollectionTest extends TestCase
{
    public function testShouldQuoteRegexCorrectly(): void
    {
        $obj = new AwsObject([
            'Key' => '/asd#one/test/##two/test.log'
        ]);
        $aclOutput = new GetObjectAclOutput(new Response(
            new SimpleMockedResponse(<<<'EOT'
                <?xml version="1.0" encoding="UTF-8"?>
                <AccessControlPolicy>
                   <Owner>
                      <DisplayName>Foobar</DisplayName>
                      <ID>0123456789</ID>
                   </Owner>
                   <AccessControlList>
                      <Grant>
                         <Grantee>
                            <ID>0123456789</ID>
                            <xsi:type>string</xsi:type>
                            <URI>http://acs.amazonaws.com/groups/global/AllUsers</URI>
                         </Grantee>
                         <Permission>READ</Permission>
                      </Grant>
                   </AccessControlList>
                </AccessControlPolicy>
            EOT),
            new MockHttpClient(),
            new NullLogger()
        ));

        $collection = new AsyncS3Collection([$obj], '/asd#one/test/##two', static fn () => $aclOutput);
        self::assertEquals([
            new S3FileStat($obj, $obj->getKey(), '/test.log', static fn () => $aclOutput)
        ], iterator_to_array($collection));
    }
}
