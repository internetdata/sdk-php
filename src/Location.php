<?php

declare(strict_types=1);

namespace InternetData;

use InternetData\Internal\Model\Location as WireLocation;

/**
 * Where the address is.
 *
 * A property only a paid plan or the trial answers is null on Unlimited, which means not in your
 * plan. The rest are set whenever the object is.
 */
final class Location
{
    public function __construct(
        /** Continent code. */
        public readonly string $continent,
        /** Continent name. */
        public readonly string $continentName,
        /** ISO 3166-1 alpha-2 country code. */
        public readonly string $country,
        /** Country name. */
        public readonly string $countryName,
        /** IANA timezone. */
        public readonly string $timezone,
        /** ISO 4217 code. */
        public readonly string $currencyCode,
        /** Currency name. */
        public readonly string $currencyName,
        /** Currency symbol. */
        public readonly string $currencySymbol,
        /** The country's flag. */
        public readonly string $flagEmoji,
        /** The flag's code points, e.g. `U+1F1E6 U+1F1F9`. */
        public readonly string $flagUnicode,
        /** Region (state or province). Paid plans and the trial. */
        public readonly ?string $region = null,
        /** ISO 3166-2 region code. Paid plans and the trial. */
        public readonly ?string $regionCode = null,
        /** City. Paid plans and the trial. */
        public readonly ?string $city = null,
        /** Postal code. Paid plans and the trial. */
        public readonly ?string $postalCode = null,
        /** Latitude of the city. Paid plans and the trial. */
        public readonly ?float $latitude = null,
        /** Longitude of the city. Paid plans and the trial. */
        public readonly ?float $longitude = null,
        /** Radius in km holding the true location with 67% confidence. Paid plans and the trial. */
        public readonly ?int $accuracyRadius = null,
        /** GeoNames ID of the city. Paid plans and the trial. */
        public readonly ?string $geonameId = null,
        /** US TV market (DMA) code. Paid plans and the trial. */
        public readonly ?string $dmaCode = null,
        /** The day the range's location last changed. Paid plans and the trial. */
        public readonly ?string $lastChanged = null,
    ) {
    }

    /** @internal */
    public static function fromWire(WireLocation $w): self
    {
        return new self(
            continent: $w->getContinent(),
            continentName: $w->getContinentName(),
            country: $w->getCountry(),
            countryName: $w->getCountryName(),
            timezone: $w->getTimezone(),
            currencyCode: $w->getCurrencyCode(),
            currencyName: $w->getCurrencyName(),
            currencySymbol: $w->getCurrencySymbol(),
            flagEmoji: $w->getFlagEmoji(),
            flagUnicode: $w->getFlagUnicode(),
            region: $w->getRegion(),
            regionCode: $w->getRegionCode(),
            city: $w->getCity(),
            postalCode: $w->getPostalCode(),
            latitude: $w->getLatitude(),
            longitude: $w->getLongitude(),
            accuracyRadius: $w->getAccuracyRadius(),
            geonameId: $w->getGeonameId(),
            dmaCode: $w->getDmaCode(),
            lastChanged: $w->getLastChanged(),
        );
    }
}
